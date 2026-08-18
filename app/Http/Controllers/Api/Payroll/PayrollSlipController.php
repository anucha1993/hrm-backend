<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Exports\PayrollSlipsExport;
use App\Http\Controllers\Controller;
use App\Models\GoodsDepositSlip;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipItem;
use App\Services\Payroll\PayrollApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollSlipController extends Controller
{
    public function __construct(protected PayrollApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $q = PayrollSlip::with(['employee:id,employee_code,first_name,last_name', 'period:id,name,code,start_date,end_date,pay_date'])
            ->orderByDesc('id');
        if ($pid = $request->integer('period_id')) {
            $q->where('payroll_period_id', $pid);
        }
        if ($eid = $request->integer('employee_id')) {
            $q->where('employee_id', $eid);
        }
        if ($s = $request->string('status')->toString()) {
            $q->where('status', $s);
        }
        if ($request->boolean('mine')) {
            $userEmpId = optional($request->user()->employee)->id;
            $q->where('employee_id', $userEmpId ?? -1);
        }
        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    /**
     * GET /payroll/slips/export
     */
    public function export(Request $request): BinaryFileResponse
    {
        $q = PayrollSlip::with(['employee:id,employee_code,first_name,last_name', 'period:id,name,code,start_date,end_date,pay_date'])
            ->orderByDesc('id');
        if ($pid = $request->integer('period_id')) $q->where('payroll_period_id', $pid);
        if ($eid = $request->integer('employee_id')) $q->where('employee_id', $eid);
        if ($s = $request->string('status')->toString()) $q->where('status', $s);
        if ($request->boolean('mine')) {
            $userEmpId = optional($request->user()->employee)->id;
            $q->where('employee_id', $userEmpId ?? -1);
        }
        $records = $q->limit(50000)->get();
        return Excel::download(new PayrollSlipsExport($records), 'payroll-slips-' . now()->format('Ymd-Hi') . '.xlsx');
    }

    public function show(PayrollSlip $slip, Request $request): JsonResponse
    {
        // พนักงานทั่วไปดูได้เฉพาะของตน
        $user = $request->user();
        if (! $user->hasPermission('payroll.compute')
            && ! $user->hasPermission('payroll.approve_l1')
            && ! $user->hasPermission('payroll.approve_l2')) {
            $empId = optional($user->employee)->id;
            abort_unless($slip->employee_id === $empId, 403);
        }

        $slip->load(['items', 'employee', 'period', 'approvals.user:id,name']);

        // ถ้ามีรายการค่าจ้างการผลิต ให้แนบใบงาน (work orders) ของหัวหน้าทีมในงวดนี้มาด้วย
        if ($slip->items->contains(fn ($item) => $item->code === 'PRODUCTION_WAGE')) {
            $slip->setAttribute('work_orders', \App\Models\WorkOrder::where('payroll_period_id', $slip->payroll_period_id)
                ->where('team_leader_id', $slip->employee_id)
                ->orderBy('code')
                ->get(['id', 'code', 'status', 'total_amount', 'start_date', 'end_date']));
        }

        return response()->json([
            'data' => $slip,
        ]);
    }

    public function destroy(PayrollSlip $slip): JsonResponse
    {
        if (in_array($slip->status, ['approved', 'paid'])) {
            return response()->json(['message' => 'สลิปนี้ปิดแล้ว ลบไม่ได้'], 422);
        }
        // คืนสถานะใบมัดจำของใช้ทั่วไปที่เคยตัดไว้ในสลิปนี้กลับเป็น pending ก่อนลบ
        GoodsDepositSlip::where('payslip_id', $slip->id)->update([
            'status' => GoodsDepositSlip::STATUS_PENDING,
            'payroll_period_id' => null,
            'payslip_id' => null,
            'deducted_at' => null,
        ]);
        $slip->items()->delete();
        $slip->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    public function submitL1(PayrollSlip $slip, Request $request): JsonResponse
    {
        $note = $request->input('note');
        return response()->json(['data' => $this->approvals->submitToL1($slip, $request->user()->id, $note)]);
    }

    public function approveL1(PayrollSlip $slip, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->approvals->approveL1($slip, $request->user()->id, $request->input('note'))]);
    }

    public function rejectL1(PayrollSlip $slip, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->approvals->rejectL1($slip, $request->user()->id, $request->input('note'))]);
    }

    public function approveL2(PayrollSlip $slip, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->approvals->approveL2($slip, $request->user()->id, $request->input('note'))]);
    }

    public function rejectL2(PayrollSlip $slip, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->approvals->rejectL2($slip, $request->user()->id, $request->input('note'))]);
    }

    public function markPaid(PayrollSlip $slip, Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ]);
        return response()->json([
            'data' => $this->approvals->markPaid($slip, $request->user()->id, $data['payment_reference'] ?? null, $data['note'] ?? null),
        ]);
    }

    public function cancel(PayrollSlip $slip, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->approvals->cancel($slip, $request->user()->id, $request->input('note'))]);
    }

    /**
     * ลบรายการหักที่เพิ่มเอง หรือยกเลิกการตัดยอดใบมัดจำรายการเดียว (คืนสถานะใบมัดจำเป็น pending)
     * DELETE /payroll/slips/{slip}/deductions/{item}
     */
    public function removeDeduction(PayrollSlip $slip, PayrollSlipItem $item): JsonResponse
    {
        if (! in_array($slip->status, ['draft', 'computed'])) {
            return response()->json(['message' => 'สลิปนี้อยู่ในสถานะที่แก้ไขไม่ได้แล้ว'], 422);
        }
        if ($item->payroll_slip_id !== $slip->id) {
            abort(404);
        }
        if ($item->type !== 'deduction' || $item->source !== 'manual' || $item->code === 'CAP-ADJ') {
            return response()->json(['message' => 'ลบรายการนี้ไม่ได้ — แก้ไขได้เฉพาะรายการที่เพิ่ม/ตัดยอดเอง'], 422);
        }

        $amount = (float) $item->amount;

        if ($item->reference_type === GoodsDepositSlip::class && $item->reference_id) {
            GoodsDepositSlip::where('id', $item->reference_id)->update([
                'status' => GoodsDepositSlip::STATUS_PENDING,
                'payroll_period_id' => null,
                'payslip_id' => null,
                'deducted_at' => null,
            ]);
        }

        $item->delete();

        $slip->other_deductions_total = max(0, (float) $slip->other_deductions_total - $amount);
        $slip->deductions_total       = max(0, (float) $slip->deductions_total - $amount);
        $slip->net_pay                = (float) $slip->net_pay + $amount;
        $slip->save();

        return response()->json(['data' => $slip->fresh()->load('items')]);
    }

    /**
     * ดำเนินการแบบ bulk (เช่น submit หลายสลิปทีเดียว)
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slip_ids' => ['required', 'array', 'min:1'],
            'slip_ids.*' => ['integer', 'exists:payroll_slips,id'],
            'action' => ['required', 'in:submit_l1,approve_l1,reject_l1,approve_l2,reject_l2,mark_paid,cancel'],
            'note' => ['nullable', 'string'],
            'payment_reference' => ['nullable', 'string'],
        ]);
        $userId = $request->user()->id;
        $note = $data['note'] ?? null;
        $ok = [];
        $errors = [];
        foreach (PayrollSlip::whereIn('id', $data['slip_ids'])->get() as $slip) {
            try {
                $result = match ($data['action']) {
                    'submit_l1'  => $this->approvals->submitToL1($slip, $userId, $note),
                    'approve_l1' => $this->approvals->approveL1($slip, $userId, $note),
                    'reject_l1'  => $this->approvals->rejectL1($slip, $userId, $note),
                    'approve_l2' => $this->approvals->approveL2($slip, $userId, $note),
                    'reject_l2'  => $this->approvals->rejectL2($slip, $userId, $note),
                    'mark_paid'  => $this->approvals->markPaid($slip, $userId, $data['payment_reference'] ?? null, $note),
                    'cancel'     => $this->approvals->cancel($slip, $userId, $note),
                };
                $ok[] = ['slip_id' => $slip->id, 'status' => $result->status];
            } catch (\Throwable $e) {
                $errors[] = ['slip_id' => $slip->id, 'message' => $e->getMessage()];
            }
        }
        return response()->json(['data' => ['success' => $ok, 'errors' => $errors]]);
    }
}

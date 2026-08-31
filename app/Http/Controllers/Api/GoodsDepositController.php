<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\GoodsDepositSlip;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GoodsDepositController extends Controller
{
    private const RELATIONS = ['employee:id,employee_code,first_name,last_name', 'items', 'payrollPeriod:id,name,code', 'creator:id,name'];

    public function index(Request $request): JsonResponse
    {
        $q = GoodsDepositSlip::with(self::RELATIONS)->orderByDesc('deposit_date')->orderByDesc('id');

        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
        if ($status = $request->string('status')->toString()) $q->where('status', $status);
        if ($from = $request->string('from')->toString()) $q->whereDate('deposit_date', '>=', $from);
        if ($to = $request->string('to')->toString())     $q->whereDate('deposit_date', '<=', $to);

        if ($s = $request->string('search')->toString()) {
            $q->where(function ($w) use ($s) {
                $w->where('slip_no', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($e) use ($s) {
                      $e->where('employee_code', 'like', "%{$s}%")
                        ->orWhere('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%");
                  });
            });
        }

        return response()->json([
            'data'    => $q->paginate($request->integer('per_page', 20)),
            'summary' => [
                'pending_total' => (float) GoodsDepositSlip::where('status', 'pending')->sum('total_amount'),
            ],
        ]);
    }

    public function show(GoodsDepositSlip $deposit): JsonResponse
    {
        return response()->json(['data' => $deposit->load([...self::RELATIONS, 'payslip:id,slip_no'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        return DB::transaction(function () use ($data, $request) {
            $depositDate = Carbon::parse($data['deposit_date']);
            $slip = GoodsDepositSlip::create([
                'slip_no'       => GoodsDepositSlip::generateSlipNo($depositDate),
                'employee_id'   => $data['employee_id'],
                'deposit_date'  => $data['deposit_date'],
                'status'        => GoodsDepositSlip::STATUS_PENDING,
                'note'          => $data['note'] ?? null,
                'created_by'    => $request->user()?->id,
                'source'        => GoodsDepositSlip::SOURCE_MANUAL,
                'total_amount'  => 0,
            ]);

            $this->syncItems($slip, $data['items']);

            return response()->json(['data' => $slip->load(self::RELATIONS)], 201);
        });
    }

    /**
     * รับข้อมูลจาก labour-app-importer เมื่อรายการหักชำระเงินครบ (machine-to-machine, token auth)
     * POST /api/labour/goods-deposits
     */
    public function storeFromLabour(Request $request): JsonResponse
    {
        $data = $request->validate([
            'labour_id'          => ['required', 'integer'],
            'deposit_date'       => ['required', 'date'],
            'note'               => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.item_name'  => ['required', 'string', 'max:255'],
            'items.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.note'       => ['nullable', 'string', 'max:255'],
        ]);

        $employee = Employee::where('labour_id', $data['labour_id'])->first();
        if (! $employee) {
            return response()->json(['message' => "ไม่พบพนักงานที่เชื่อมกับ labour_id={$data['labour_id']} ในระบบ HRM"], 422);
        }

        return DB::transaction(function () use ($data, $employee) {
            $depositDate = Carbon::parse($data['deposit_date']);
            $slip = GoodsDepositSlip::create([
                'slip_no'      => GoodsDepositSlip::generateSlipNo($depositDate),
                'employee_id'  => $employee->id,
                'deposit_date' => $data['deposit_date'],
                'status'       => GoodsDepositSlip::STATUS_PENDING,
                'note'         => $data['note'] ?? null,
                'created_by'   => null,
                'source'       => GoodsDepositSlip::SOURCE_LABOUR_API,
                'total_amount' => 0,
            ]);

            $this->syncItems($slip, $data['items']);

            return response()->json(['data' => $slip->load(self::RELATIONS)], 201);
        });
    }

    public function update(Request $request, GoodsDepositSlip $deposit): JsonResponse
    {
        if ($deposit->status === GoodsDepositSlip::STATUS_DEDUCTED) {
            return response()->json(['message' => 'ใบนี้ถูกตัดยอดเข้า payroll แล้ว ไม่สามารถแก้ไขได้'], 422);
        }

        $data = $this->validateData($request, $deposit->id);

        return DB::transaction(function () use ($data, $deposit) {
            $deposit->update([
                'employee_id'  => $data['employee_id'],
                'deposit_date' => $data['deposit_date'],
                'note'         => $data['note'] ?? null,
            ]);

            $this->syncItems($deposit, $data['items']);

            return response()->json(['data' => $deposit->fresh()->load(self::RELATIONS)]);
        });
    }

    public function destroy(GoodsDepositSlip $deposit): JsonResponse
    {
        if ($deposit->status === GoodsDepositSlip::STATUS_DEDUCTED) {
            return response()->json(['message' => 'ใบนี้ถูกตัดยอดเข้า payroll แล้ว ไม่สามารถลบได้ — ให้ใช้สถานะ cancelled แทน'], 422);
        }
        $deposit->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /**
     * เปลี่ยนสถานะ (cancel / waive)
     * POST /api/goods-deposits/{deposit}/status
     */
    public function changeStatus(Request $request, GoodsDepositSlip $deposit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'cancelled', 'waived'])],
            'note'   => ['nullable', 'string'],
        ]);

        if ($deposit->status === GoodsDepositSlip::STATUS_DEDUCTED) {
            return response()->json(['message' => 'ใบนี้ถูกตัดยอดเข้า payroll แล้ว ไม่สามารถเปลี่ยนสถานะได้'], 422);
        }

        $deposit->update([
            'status' => $data['status'],
            'note'   => $data['note'] ?? $deposit->note,
        ]);

        return response()->json(['data' => $deposit->fresh()->load(self::RELATIONS)]);
    }

    /**
     * Preview ยอดที่จะหักของ payslip ใดๆ (ใบ pending ในช่วง period + แจ้งใบนอกงวดที่ยังรอตัดแยกไว้ต่างหาก)
     * GET /api/goods-deposits/preview-for-payslip/{payslip}
     */
    public function previewForPayslip(PayrollSlip $payslip): JsonResponse
    {
        $period = $payslip->period;
        $deposits = GoodsDepositSlip::with('items')
            ->where('employee_id', $payslip->employee_id)
            ->where('status', GoodsDepositSlip::STATUS_PENDING)
            ->whereBetween('deposit_date', [$period->start_date, $period->end_date])
            ->orderBy('deposit_date')
            ->get();

        // ใบที่รอตัดแต่วันที่หยิบของอยู่นอกช่วงงวดนี้ — ไม่ตัดให้อัตโนมัติ ต้องให้ผู้ใช้เลือกตัดเองแบบ manual
        $outOfPeriod = GoodsDepositSlip::with('items')
            ->where('employee_id', $payslip->employee_id)
            ->where('status', GoodsDepositSlip::STATUS_PENDING)
            ->where(function ($w) use ($period) {
                $w->where('deposit_date', '<', $period->start_date)
                  ->orWhere('deposit_date', '>', $period->end_date);
            })
            ->orderBy('deposit_date')
            ->get();

        return response()->json([
            'data'          => $deposits,
            'total'         => (float) $deposits->sum('total_amount'),
            'out_of_period' => $outOfPeriod,
            'out_of_period_total' => (float) $outOfPeriod->sum('total_amount'),
        ]);
    }

    /**
     * ตัดยอดเข้า payslip — เพิ่มเป็น PayrollSlipItem type=deduction
     * ใบในงวดถูกเลือกอัตโนมัติ ส่วนใบนอกงวดต้องระบุ deposit_ids มาเอง (manual)
     * POST /api/goods-deposits/apply-to-payslip/{payslip}
     */
    public function applyToPayslip(Request $request, PayrollSlip $payslip): JsonResponse
    {
        if (! in_array($payslip->status, ['draft', 'computed'])) {
            return response()->json(['message' => 'สลิปนี้อยู่ในสถานะที่แก้ไขไม่ได้แล้ว'], 422);
        }

        $period = $payslip->period;
        $manualIds = collect($request->input('deposit_ids', []))->map(fn ($v) => (int) $v)->filter()->all();

        return DB::transaction(function () use ($payslip, $period, $manualIds) {
            $auto = GoodsDepositSlip::with('items')
                ->where('employee_id', $payslip->employee_id)
                ->where('status', GoodsDepositSlip::STATUS_PENDING)
                ->whereBetween('deposit_date', [$period->start_date, $period->end_date])
                ->lockForUpdate()
                ->get();

            $manual = collect();
            if (! empty($manualIds)) {
                // ใบนอกงวดที่ผู้ใช้เลือกตัดยอดเองแบบ manual (ต้องเป็นของพนักงานคนนี้และยังรอตัดอยู่)
                $manual = GoodsDepositSlip::with('items')
                    ->whereIn('id', $manualIds)
                    ->where('employee_id', $payslip->employee_id)
                    ->where('status', GoodsDepositSlip::STATUS_PENDING)
                    ->lockForUpdate()
                    ->get();
            }

            $deposits = $auto->concat($manual)->unique('id')->values();

            if ($deposits->isEmpty()) {
                return response()->json(['message' => 'ไม่มีใบมัดจำที่รอหักในงวดนี้', 'count' => 0, 'total' => 0]);
            }

            // เดิม query กรอง status=pending อยู่แล้ว จึงไม่มีใบเดิมที่ถูกตัดไปแล้วปนมาซ้ำ
            // ไม่ลบรายการเก่าทั้งหมดแบบ blanket delete เพราะจะทำให้ใบที่ตัดยอดไปก่อนหน้ากลายเป็น
            // สถานะ deducted แต่ไม่มีรายการหักอยู่ในสลิป (ยอดรวมไม่ตรง)
            $order = 900 + PayrollSlipItem::where('payroll_slip_id', $payslip->id)
                ->where('reference_type', GoodsDepositSlip::class)
                ->count();

            $total = 0;
            foreach ($deposits as $d) {
                PayrollSlipItem::create([
                    'payroll_slip_id' => $payslip->id,
                    'type'            => 'deduction',
                    'source'          => 'manual',
                    'code'            => 'GOODS_DEPOSIT',
                    'name'            => "หักค่ามัดจำของใช้ทั่วไป ({$d->slip_no})",
                    'amount'          => $d->total_amount,
                    'reference_id'    => $d->id,
                    'reference_type'  => GoodsDepositSlip::class,
                    'order'           => $order++,
                ]);
                $total += (float) $d->total_amount;

                $d->update([
                    'status'            => GoodsDepositSlip::STATUS_DEDUCTED,
                    'payroll_period_id' => $period->id,
                    'payslip_id'        => $payslip->id,
                    'deducted_at'       => now(),
                ]);
            }

            // อัปเดตยอดรวมหักในสลิป (ให้ controller payroll คำนวณรวมใหม่ถ้ามี endpoint แยก;
            // ที่นี่อัปเดต other_deductions_total + deductions_total + net_pay แบบตรงไปตรงมา)
            $payslip->other_deductions_total = (float) $payslip->other_deductions_total + $total;
            $payslip->deductions_total       = (float) $payslip->deductions_total + $total;
            $payslip->net_pay                = (float) $payslip->net_pay - $total;
            $payslip->save();

            return response()->json([
                'message' => "ตัดยอดสำเร็จ {$deposits->count()} ใบ รวม " . number_format($total, 2) . " บาท",
                'count'   => $deposits->count(),
                'total'   => $total,
            ]);
        });
    }

    /**
     * ยกเลิกการตัดยอด (revoke) — คืนสถานะใบเป็น pending + ลบ PayrollSlipItem
     * POST /api/goods-deposits/revoke-from-payslip/{payslip}
     */
    public function revokeFromPayslip(PayrollSlip $payslip): JsonResponse
    {
        if (! in_array($payslip->status, ['draft', 'computed'])) {
            return response()->json(['message' => 'สลิปนี้อยู่ในสถานะที่แก้ไขไม่ได้แล้ว'], 422);
        }

        return DB::transaction(function () use ($payslip) {
            $items = PayrollSlipItem::where('payroll_slip_id', $payslip->id)
                ->where('reference_type', GoodsDepositSlip::class)
                ->get();

            $total = (float) $items->sum('amount');

            $depositIds = $items->pluck('reference_id')->filter()->all();
            GoodsDepositSlip::whereIn('id', $depositIds)->update([
                'status'            => GoodsDepositSlip::STATUS_PENDING,
                'payroll_period_id' => null,
                'payslip_id'        => null,
                'deducted_at'       => null,
            ]);

            PayrollSlipItem::whereIn('id', $items->pluck('id'))->delete();

            $payslip->other_deductions_total = max(0, (float) $payslip->other_deductions_total - $total);
            $payslip->deductions_total       = max(0, (float) $payslip->deductions_total - $total);
            $payslip->net_pay                = (float) $payslip->net_pay + $total;
            $payslip->save();

            return response()->json([
                'message' => "ยกเลิกการตัดยอด " . count($depositIds) . " ใบ คืนยอด " . number_format($total, 2) . " บาท",
                'count'   => count($depositIds),
                'total'   => $total,
            ]);
        });
    }

    /* ===================== Helpers ===================== */

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'employee_id'        => ['required', 'integer', 'exists:employees,id'],
            'deposit_date'       => ['required', 'date'],
            'note'               => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.item_name'  => ['required', 'string', 'max:255'],
            'items.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.note'       => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function syncItems(GoodsDepositSlip $slip, array $items): void
    {
        $slip->items()->delete();
        foreach (array_values($items) as $i => $item) {
            $qty   = (float) $item['qty'];
            $price = (float) $item['unit_price'];
            $slip->items()->create([
                'item_name'  => $item['item_name'],
                'qty'        => $qty,
                'unit_price' => $price,
                'amount'     => round($qty * $price, 2),
                'note'       => $item['note'] ?? null,
                'order'      => $i,
            ]);
        }
        $slip->recalcTotal();
    }
}

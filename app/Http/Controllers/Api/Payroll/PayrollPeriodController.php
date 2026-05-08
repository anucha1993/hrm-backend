<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Services\Payroll\PayrollCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = PayrollPeriod::withCount('slips')->orderByDesc('start_date');
        if ($s = $request->string('status')->toString()) {
            $q->where('status', $s);
        }
        if ($from = $request->date('from')) {
            $q->whereDate('end_date', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $q->whereDate('start_date', '<=', $to);
        }
        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    public function show(PayrollPeriod $period): JsonResponse
    {
        return response()->json(['data' => $period->loadCount('slips')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:payroll_periods,code'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()?->id;
        return response()->json(['data' => PayrollPeriod::create($data)], 201);
    }

    public function update(Request $request, PayrollPeriod $period): JsonResponse
    {
        if (in_array($period->status, ['paid', 'approved'])) {
            return response()->json(['message' => 'งวดนี้ปิดแล้ว แก้ไขไม่ได้'], 422);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('payroll_periods', 'code')->ignore($period->id)],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'computing', 'pending_l1', 'pending_l2', 'approved', 'paid', 'cancelled'])],
            'note' => ['nullable', 'string'],
        ]);
        $period->update($data);
        return response()->json(['data' => $period->fresh()]);
    }

    public function destroy(PayrollPeriod $period): JsonResponse
    {
        if ($period->slips()->whereIn('status', ['paid', 'approved'])->exists()) {
            return response()->json(['message' => 'งวดนี้มีสลิปที่อนุมัติ/จ่ายแล้ว ลบไม่ได้'], 422);
        }
        $period->slips()->delete();
        $period->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /**
     * คำนวณสลิปสำหรับพนักงานหลายคนในงวดนี้ทีเดียว
     */
    public function compute(Request $request, PayrollPeriod $period, PayrollCalculationService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'all_active' => ['sometimes', 'boolean'],
        ]);

        $employees = ! empty($data['employee_ids'])
            ? Employee::whereIn('id', $data['employee_ids'])->get()
            : Employee::where('status', Employee::STATUS_ACTIVE)->get();

        $results = [];
        $errors = [];
        foreach ($employees as $emp) {
            try {
                $slip = $service->computeForEmployee($period, $emp, $request->user()?->id);
                $results[] = [
                    'employee_id' => $emp->id,
                    'slip_id' => $slip->id,
                    'net_pay' => $slip->net_pay,
                    'status' => $slip->status,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'employee_id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => [
                'period' => $period->fresh()->loadCount('slips'),
                'computed' => $results,
                'errors' => $errors,
            ],
        ]);
    }
}

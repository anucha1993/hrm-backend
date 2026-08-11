<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Services\EmployeeAdvance\EmployeeAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAdvanceController extends Controller
{
    public function __construct(protected EmployeeAdvanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = EmployeeAdvance::with(['employee:id,employee_code,first_name,last_name', 'approver:id,name', 'payer:id,name'])
            ->orderByDesc('id');

        if (! $user->hasPermission('advance.approve')) {
            $q->where('employee_id', optional($user->employee)->id ?? -1);
        } elseif ($eid = $request->integer('employee_id')) {
            $q->where('employee_id', $eid);
        } elseif ($request->boolean('mine')) {
            $q->where('employee_id', optional($user->employee)->id ?? -1);
        }

        if ($s = $request->string('status')->toString()) {
            $q->where('status', $s);
        }

        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    public function show(EmployeeAdvance $advance, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('advance.approve')) {
            abort_unless($advance->employee_id === optional($user->employee)->id, 403);
        }
        return response()->json([
            'data' => $advance->load([
                'employee', 'approver:id,name', 'payer:id,name', 'creator:id,name',
                'repayments' => fn ($q) => $q->orderByDesc('repaid_at'),
                'repayments.recorder:id,name', 'repayments.payrollPeriod:id,name,code',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $rules = [
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_date' => ['nullable', 'date'],
        ];

        // HR/ผู้มีสิทธิ์อนุมัติ สามารถบันทึกแทนพนักงานคนอื่นได้
        if ($user->hasPermission('advance.approve')) {
            $rules['employee_id'] = ['required', 'exists:employees,id'];
        }
        $data = $request->validate($rules);

        if (! isset($data['employee_id'])) {
            $data['employee_id'] = optional($user->employee)->id;
            abort_if(! $data['employee_id'], 422, 'บัญชีนี้ไม่ผูกกับพนักงาน');
        }

        $advance = $this->service->create($data, $user->id);
        return response()->json(['data' => $advance], 201);
    }

    public function approve(EmployeeAdvance $advance, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $advance = $this->service->approve($advance, $request->user()->id, $data['note'] ?? null);
        return response()->json(['data' => $advance]);
    }

    public function reject(EmployeeAdvance $advance, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $advance = $this->service->reject($advance, $request->user()->id, $data['note'] ?? null);
        return response()->json(['data' => $advance]);
    }

    public function cancel(EmployeeAdvance $advance, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('advance.approve') && optional($user->employee)->id !== $advance->employee_id) {
            abort(403);
        }
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $advance = $this->service->cancel($advance, $user->id, $data['note'] ?? null);
        return response()->json(['data' => $advance]);
    }

    public function markPaid(EmployeeAdvance $advance, Request $request): JsonResponse
    {
        $advance = $this->service->markPaid($advance, $request->user()->id);
        return response()->json(['data' => $advance]);
    }

    public function addRepayment(EmployeeAdvance $advance, Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'repaid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'payroll_period_id' => ['nullable', 'exists:payroll_periods,id'],
        ]);
        $advance = $this->service->addRepayment($advance, $data, $request->user()->id);
        return response()->json(['data' => $advance]);
    }

    public function deleteRepayment(EmployeeAdvanceRepayment $repayment, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('advance.approve'), 403);
        $advance = $this->service->deleteRepayment($repayment);
        return response()->json(['data' => $advance]);
    }
}

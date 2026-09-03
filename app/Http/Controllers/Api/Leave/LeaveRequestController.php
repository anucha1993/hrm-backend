<?php

namespace App\Http\Controllers\Api\Leave;

use App\Exports\LeaveRequestsExport;
use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeaveRequestController extends Controller
{
    public function __construct(protected LeaveService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = LeaveRequest::with(['employee:id,employee_code,first_name,last_name', 'leaveType', 'reviewer:id,name'])
            ->orderByDesc('id');

        // ถ้าไม่มีสิทธิ์ดูทั้งหมด — ดูได้เฉพาะของตัวเอง
        if (! $user->hasPermission('leave.approve') && ! $user->hasPermission('leave.config')) {
            $q->where('employee_id', optional($user->employee)->id ?? -1);
        } elseif ($eid = $request->integer('employee_id')) {
            $q->where('employee_id', $eid);
        } elseif ($request->boolean('mine')) {
            $q->where('employee_id', optional($user->employee)->id ?? -1);
        }

        if ($s = $request->string('status')->toString()) {
            $q->where('status', $s);
        }
        if ($tid = $request->integer('leave_type_id')) {
            $q->where('leave_type_id', $tid);
        }
        if ($from = $request->date('from')) {
            $q->where('end_date', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $q->where('start_date', '<=', $to);
        }

        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    /**
     * GET /leave/requests/export
     */
    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $q = LeaveRequest::with(['employee:id,employee_code,first_name,last_name', 'leaveType', 'reviewer:id,name'])
            ->orderByDesc('id');
        if (! $user->hasPermission('leave.approve') && ! $user->hasPermission('leave.config')) {
            $q->where('employee_id', optional($user->employee)->id ?? -1);
        } elseif ($eid = $request->integer('employee_id')) {
            $q->where('employee_id', $eid);
        } elseif ($request->boolean('mine')) {
            $q->where('employee_id', optional($user->employee)->id ?? -1);
        }
        if ($s = $request->string('status')->toString()) $q->where('status', $s);
        if ($tid = $request->integer('leave_type_id')) $q->where('leave_type_id', $tid);
        if ($from = $request->date('from')) $q->where('end_date', '>=', $from);
        if ($to = $request->date('to')) $q->where('start_date', '<=', $to);
        $records = $q->limit(50000)->get();
        return Excel::download(new LeaveRequestsExport($records), 'leave-requests-' . now()->format('Ymd-Hi') . '.xlsx');
    }

    public function show(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('leave.approve') && ! $user->hasPermission('leave.config')) {
            $empId = optional($user->employee)->id;
            abort_unless($leaveRequest->employee_id === $empId, 403);
        }
        return response()->json([
            'data' => $leaveRequest->load(['employee', 'leaveType', 'reviewer:id,name', 'logs.user:id,name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $rules = [
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_half_day' => ['sometimes', 'boolean'],
            'half_day_period' => ['nullable', Rule::in(['morning', 'afternoon'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'attachment_path' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ];

        // HR สามารถยื่นแทนพนักงานได้
        if ($user->hasPermission('leave.config')) {
            $rules['employee_id'] = ['required', 'exists:employees,id'];
        }
        $data = $request->validate($rules);

        if (! isset($data['employee_id'])) {
            $data['employee_id'] = optional($user->employee)->id;
            abort_if(! $data['employee_id'], 422, 'บัญชีนี้ไม่ผูกกับพนักงาน');
        }

        // HR/admin ยื่นแทนพนักงานได้โดยไม่ติดเงื่อนไข "ต้องแจ้งล่วงหน้า X วัน"
        $req = $this->service->create($data, $user->id, $user->hasPermission('leave.config'));
        return response()->json(['data' => $req], 201);
    }

    public function approve(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $req = $this->service->approve($leaveRequest, $request->user()->id, $request->input('note'));
        return response()->json(['data' => $req]);
    }

    public function reject(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $req = $this->service->reject($leaveRequest, $request->user()->id, $request->input('note'));
        return response()->json(['data' => $req]);
    }

    public function cancel(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $user = $request->user();
        // เจ้าของยกเลิกได้, หรือมี leave.approve
        if (! $user->hasPermission('leave.approve') && optional($user->employee)->id !== $leaveRequest->employee_id) {
            abort(403);
        }
        $req = $this->service->cancel($leaveRequest, $user->id, $request->input('note'));
        return response()->json(['data' => $req]);
    }

    /**
     * ดูสรุปวันลาคงเหลือของพนักงาน (default = ผู้เรียกเอง)
     */
    public function balances(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $request->integer('employee_id') ?: optional($user->employee)->id;
        if (! $employeeId) {
            return response()->json(['data' => []]);
        }
        // ตรวจสิทธิ์
        if ($employeeId !== optional($user->employee)->id
            && ! $user->hasPermission('leave.approve')
            && ! $user->hasPermission('leave.config')) {
            abort(403);
        }

        $year = $request->integer('year') ?: now()->year;
        $types = LeaveType::where('is_active', true)->orderBy('order')->get();
        $balances = LeaveBalance::where('employee_id', $employeeId)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        $rows = $types->map(function ($t) use ($balances, $employeeId, $year) {
            $b = $balances->get($t->id);
            if (! $b) {
                $b = LeaveBalance::create([
                    'employee_id' => $employeeId,
                    'leave_type_id' => $t->id,
                    'year' => $year,
                    'quota_days' => $t->default_quota_days,
                ]);
            }
            return [
                'leave_type' => $t,
                'year' => (int) $b->year,
                'quota_days' => (float) $b->quota_days,
                'carryover_days' => (float) $b->carryover_days,
                'used_days' => (float) $b->used_days,
                'pending_days' => (float) $b->pending_days,
                'remaining' => (float) $b->quota_days + (float) $b->carryover_days
                    - (float) $b->used_days - (float) $b->pending_days,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * HR ปรับโควต้าวันลาตรงๆ
     */
    public function updateBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'year' => ['required', 'integer'],
            'quota_days' => ['sometimes', 'numeric', 'min:0'],
            'carryover_days' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);
        $b = LeaveBalance::firstOrCreate(
            ['employee_id' => $data['employee_id'], 'leave_type_id' => $data['leave_type_id'], 'year' => $data['year']],
            ['quota_days' => 0],
        );
        $b->update(collect($data)->only(['quota_days', 'carryover_days', 'note'])->toArray());
        return response()->json(['data' => $b]);
    }
}

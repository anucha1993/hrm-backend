<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Exports\AttendanceDailyExport;
use App\Exports\AttendanceSummaryExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OtSessionEmployee;
use App\Services\Leave\LeaveService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceSummaryController extends Controller
{
    public function __construct(protected LeaveService $leave) {}

    /**
     * GET /attendance/summary?month=YYYY-MM&department_id=&employee_id=
     * Return per-employee monthly summary
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->buildSummary($request);
        return response()->json(['data' => $result]);
    }

    /**
     * GET /attendance/summary/export?month=YYYY-MM&department_id=&employee_id=
     */
    public function export(Request $request): BinaryFileResponse
    {
        $result = $this->buildSummary($request);
        $filename = 'attendance-summary-' . ($result['period']['start'] ?? now()->format('Y-m-d')) . '-to-' . ($result['period']['end'] ?? '') . '.xlsx';
        return Excel::download(
            new AttendanceSummaryExport($result['rows']->toArray(), $result['period'], $result['totals']),
            $filename
        );
    }

    protected function buildSummary(Request $request): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer'],
            'employment_type_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['month'])) {
            $start = Carbon::parse($data['month'] . '-01')->startOfMonth();
            $end = $start->copy()->endOfMonth();
        } elseif (! empty($data['from']) && ! empty($data['to'])) {
            $start = Carbon::parse($data['from'])->startOfDay();
            $end = Carbon::parse($data['to'])->endOfDay();
        } else {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();
        }

        $user = $request->user();
        $emp = Employee::query()
            ->where('status', 'active')
            ->when($data['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($data['employment_type_id'] ?? null, fn ($q, $v) => $q->where('employment_type_id', $v))
            ->when($data['employee_id'] ?? null, fn ($q, $v) => $q->where('id', $v));

        // ถ้าไม่มีสิทธิ์ดูสรุปทั้งหมด — เห็นเฉพาะตน
        if (! $user->hasPermission('attendance.summary.view') && ! $user->hasPermission('attendance.view')) {
            $myEmpId = optional($user->employee)->id;
            $emp->where('id', $myEmpId ?? -1);
        }

        $employees = $emp->with('department:id,name')->orderBy('employee_code')->get();
        $totalDays = CarbonPeriod::create($start, $end)->count();

        $rows = $employees->map(function ($e) use ($start, $end, $totalDays) {
            $atts = Attendance::where('employee_id', $e->id)
                ->whereBetween('checked_at', [$start, $end])
                ->get();
            $byDay = $atts->groupBy(fn ($r) => $r->checked_at->toDateString());
            $presentDays = $byDay->count();
            $lateRows = $atts->filter(fn ($r) => ($r->late_minutes ?? 0) > 0 || $r->status === 'late');
            $lateCount = $lateRows->groupBy(fn ($r) => $r->checked_at->toDateString())->count();
            $lateMinutes = (int) $atts->sum('late_minutes');

            $leave = $this->leave->summarizeForPeriod($e->id, $start->toDateString(), $end->toDateString());
            $leaveDays = (float) $leave['total_days'];
            $absentDays = max(0, $totalDays - $presentDays - $leaveDays);

            $otHours = (float) OtSessionEmployee::where('employee_id', $e->id)
                ->whereHas('session', fn ($q) => $q->whereBetween('ot_date', [$start->toDateString(), $end->toDateString()]))
                ->sum('hours');

            return [
                'employee' => [
                    'id' => $e->id,
                    'employee_code' => $e->employee_code,
                    'first_name' => $e->first_name,
                    'last_name' => $e->last_name,
                    'department' => $e->department ? ['id' => $e->department->id, 'name' => $e->department->name] : null,
                ],
                'total_days' => $totalDays,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'leave_days' => $leaveDays,
                'paid_leave_days' => (float) $leave['paid_days'],
                'unpaid_leave_days' => (float) $leave['unpaid_days'],
                'leave_breakdown' => $leave['by_type'],
                'late_count' => $lateCount,
                'late_minutes' => $lateMinutes,
                'ot_hours' => $otHours,
            ];
        });

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'total_days' => $totalDays,
            ],
            'rows' => $rows,
            'totals' => [
                'employees' => $rows->count(),
                'present_days' => $rows->sum('present_days'),
                'absent_days' => $rows->sum('absent_days'),
                'leave_days' => $rows->sum('leave_days'),
                'late_count' => $rows->sum('late_count'),
                'ot_hours' => $rows->sum('ot_hours'),
            ],
        ];
    }

    /**
     * GET /attendance/summary/{employee}/daily?month=YYYY-MM
     * Daily breakdown สำหรับพนักงานคนเดียว
     */
    public function daily(Employee $employee, Request $request): JsonResponse
    {
        $result = $this->buildDaily($employee, $request);
        return response()->json(['data' => $result]);
    }

    /**
     * GET /attendance/summary/{employee}/daily/export?month=YYYY-MM
     */
    public function dailyExport(Employee $employee, Request $request): BinaryFileResponse
    {
        $result = $this->buildDaily($employee, $request);
        $filename = 'attendance-daily-' . ($result['employee']['employee_code'] ?? $employee->id) . '-' . $result['month'] . '.xlsx';
        return Excel::download(
            new AttendanceDailyExport($result['employee'], $result['month'], $result['days']),
            $filename
        );
    }

    protected function buildDaily(Employee $employee, Request $request): array
    {
        $month = $request->input('month') ?: now()->format('Y-m');
        $start = Carbon::parse($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $user = $request->user();
        if ($employee->id !== optional($user->employee)->id
            && ! $user->hasPermission('attendance.summary.view')
            && ! $user->hasPermission('attendance.view')) {
            abort(403);
        }

        $atts = Attendance::where('employee_id', $employee->id)
            ->whereBetween('checked_at', [$start, $end])
            ->orderBy('checked_at')
            ->get()
            ->groupBy(fn ($r) => $r->checked_at->toDateString());

        $leaves = \App\Models\LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                  ->orWhereBetween('end_date', [$start, $end])
                  ->orWhere(function ($qq) use ($start, $end) {
                      $qq->where('start_date', '<=', $start)->where('end_date', '>=', $end);
                  });
            })
            ->get();

        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $d) {
            $key = $d->toDateString();
            $checks = $atts->get($key);
            $checkIn = $checks?->firstWhere('type', 'in') ?? $checks?->first();
            $checkOut = $checks?->firstWhere('type', 'out') ?? $checks?->last();
            $isLate = $checks?->contains(fn ($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0) ?? false;
            $lateMinutes = (int) ($checks?->sum('late_minutes') ?? 0);

            $leaveOnDay = $leaves->first(function ($l) use ($d) {
                return $d->between($l->start_date, $l->end_date);
            });

            $status = 'absent';
            if ($leaveOnDay) {
                $status = 'leave';
            } elseif ($checks && $checks->count() > 0) {
                $status = $isLate ? 'late' : 'present';
            } elseif ($d->isWeekend()) {
                $status = 'weekend';
            }

            $days[] = [
                'date' => $key,
                'day_of_week' => $d->dayOfWeek,
                'status' => $status,
                'check_in' => $checkIn?->checked_at?->format('H:i'),
                'check_out' => $checkOut?->checked_at?->format('H:i'),
                'late_minutes' => $lateMinutes,
                'leave' => $leaveOnDay ? [
                    'type' => $leaveOnDay->leaveType->name,
                    'code' => $leaveOnDay->leaveType->code,
                    'color' => $leaveOnDay->leaveType->color,
                    'is_half_day' => (bool) $leaveOnDay->is_half_day,
                ] : null,
            ];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
            ],
            'month' => $month,
            'days' => $days,
        ];
    }
}

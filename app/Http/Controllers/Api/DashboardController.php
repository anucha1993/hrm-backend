<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OtSession;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Models\Role;
use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Role-aware dashboard summary.
     * Returns:
     *   - role: 'admin' | 'employee'
     *   - today: company-wide today stats (admin/hr/manager only)
     *   - pending: pending approval counts (admin/hr/manager only)
     *   - me: personal data (everyone with linked employee)
     *   - trends: { attendance_30d, payroll_6m } (admin/hr only)
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user?->employee;

        $isAdmin = $user && (
            $user->isSuperAdmin()
            || $user->hasRole([Role::ADMIN, Role::HR, Role::OWNER, Role::MANAGER])
        );

        $data = [
            'role'    => $isAdmin ? 'admin' : 'employee',
            'me'      => $employee ? $this->buildMe($employee) : null,
            'today'   => null,
            'pending' => null,
            'trends'  => null,
        ];

        if ($isAdmin) {
            $data['today']   = $this->buildToday();
            $data['pending'] = $this->buildPending();
            $data['trends'] = [
                'attendance_30d' => $this->attendanceTrend30d(),
                'payroll_6m'     => $this->payrollTrend6m(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /* ---------------- ME (employee-self) ---------------- */

    protected function buildMe(Employee $emp): array
    {
        $today = Carbon::today();

        // Today's check-in
        $todayCheckin = Attendance::where('employee_id', $emp->id)
            ->whereBetween('checked_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->where('type', 'check_in')
            ->orderBy('checked_at')
            ->first();

        $todayCheckout = Attendance::where('employee_id', $emp->id)
            ->whereBetween('checked_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->where('type', 'check_out')
            ->orderByDesc('checked_at')
            ->first();

        // Upcoming/overdue tasks
        $upcomingTasks = Task::with('assignees')
            ->whereHas('assignees', fn ($q) => $q->where('employee_id', $emp->id)
                ->whereNotIn('status', ['approved', 'rejected']))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id'        => $t->id,
                'code'      => $t->code,
                'title'     => $t->title,
                'priority'  => $t->priority,
                'status'    => $t->status,
                'due_date'  => $t->due_date,
                'overdue'   => $t->due_date && Carbon::parse($t->due_date)->isPast(),
            ]);

        $overdueCount = Task::whereHas('assignees', fn ($q) => $q->where('employee_id', $emp->id)
                ->whereNotIn('status', ['approved', 'rejected']))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->count();

        // Leave balance current year
        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $emp->id)
            ->where('year', $today->year)
            ->get()
            ->map(fn ($b) => [
                'leave_type' => $b->leaveType?->name,
                'quota'      => (float) $b->quota_days + (float) $b->carryover_days,
                'used'       => (float) $b->used_days,
                'pending'    => (float) $b->pending_days,
                'remaining'  => (float) $b->quota_days + (float) $b->carryover_days
                                - (float) $b->used_days - (float) $b->pending_days,
            ]);

        // Current period slip
        $slip = PayrollSlip::with('period')
            ->where('employee_id', $emp->id)
            ->whereHas('period', fn ($q) => $q
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today))
            ->first();

        if (! $slip) {
            $slip = PayrollSlip::with('period')
                ->where('employee_id', $emp->id)
                ->orderByDesc('id')
                ->first();
        }

        return [
            'employee' => [
                'id'             => $emp->id,
                'employee_code'  => $emp->employee_code,
                'name'           => trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? '')),
                'department'     => optional($emp->department)->name,
            ],
            'today_attendance' => [
                'check_in_at'  => $todayCheckin?->checked_at,
                'check_out_at' => $todayCheckout?->checked_at,
                'late_minutes' => $todayCheckin?->late_minutes ?? 0,
                'status'       => $todayCheckin?->status,
            ],
            'tasks' => [
                'upcoming'      => $upcomingTasks,
                'overdue_count' => $overdueCount,
            ],
            'leave_balances' => $balances,
            'latest_slip'    => $slip ? [
                'slip_id'     => $slip->id,
                'period_name' => $slip->period?->name,
                'period_code' => $slip->period?->code,
                'status'      => $slip->status,
                'net_pay'     => (float) $slip->net_pay,
                'pay_date'    => $slip->period?->pay_date,
            ] : null,
        ];
    }

    /* ---------------- TODAY (company-wide) ---------------- */

    protected function buildToday(): array
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        $activeEmployees = Employee::where('status', Employee::STATUS_ACTIVE)
            ->pluck('id');
        $activeTotal = $activeEmployees->count();

        $todayRows = Attendance::whereIn('employee_id', $activeEmployees)
            ->whereBetween('checked_at', [$start, $end])
            ->get();

        $checkIns = $todayRows->where('type', 'check_in');
        $presentIds = $checkIns->pluck('employee_id')->unique();
        $lateIds = $checkIns
            ->filter(fn ($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0)
            ->pluck('employee_id')->unique();

        // On leave today
        $onLeaveIds = LeaveRequest::where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $start)
            ->whereDate('end_date', '>=', $end)
            ->pluck('employee_id')->unique();

        $absent = max(0, $activeTotal - $presentIds->count() - $onLeaveIds->count());

        return [
            'employees_active' => $activeTotal,
            'present'   => $presentIds->count(),
            'late'      => $lateIds->count(),
            'on_leave'  => $onLeaveIds->count(),
            'absent'    => $absent,
        ];
    }

    /* ---------------- PENDING (approvals) ---------------- */

    protected function buildPending(): array
    {
        $pendingLeave = LeaveRequest::where('status', LeaveRequest::STATUS_PENDING)->count();

        $pendingOtSessions = OtSession::where('status', 'open')->count();

        $pendingPayroll = PayrollPeriod::whereIn('status', ['pending_l1', 'pending_l2'])->count();

        return [
            'leave_requests' => $pendingLeave,
            'ot_sessions'    => $pendingOtSessions,
            'payroll_periods'=> $pendingPayroll,
        ];
    }

    /* ---------------- TRENDS ---------------- */

    protected function attendanceTrend30d(): array
    {
        $from = Carbon::today()->subDays(29)->startOfDay();
        $to = Carbon::today()->endOfDay();

        $rows = Attendance::where('type', 'check_in')
            ->whereBetween('checked_at', [$from, $to])
            ->get();

        $byDay = $rows->groupBy(fn ($r) => $r->checked_at->toDateString());

        $out = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $key = $day->toDateString();
            $g = $byDay->get($key, collect());
            $presentIds = $g->pluck('employee_id')->unique();
            $lateIds = $g->filter(fn ($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0)
                ->pluck('employee_id')->unique();
            $out[] = [
                'date'    => $key,
                'present' => $presentIds->count(),
                'late'    => $lateIds->count(),
            ];
        }
        return $out;
    }

    protected function payrollTrend6m(): array
    {
        $from = Carbon::today()->subMonths(5)->startOfMonth();

        $periods = PayrollPeriod::where('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get();

        return $periods->map(function ($p) {
            $totals = PayrollSlip::where('payroll_period_id', $p->id)
                ->selectRaw('SUM(base_salary) as base, SUM(ot_pay) as ot, SUM(bonus_total) as bonus, SUM(net_pay) as net')
                ->first();
            return [
                'period_code' => $p->code,
                'period_name' => $p->name,
                'month'       => Carbon::parse($p->start_date)->format('Y-m'),
                'base'        => (float) ($totals->base ?? 0),
                'ot'          => (float) ($totals->ot ?? 0),
                'bonus'       => (float) ($totals->bonus ?? 0),
                'net'         => (float) ($totals->net ?? 0),
            ];
        })->values()->all();
    }
}

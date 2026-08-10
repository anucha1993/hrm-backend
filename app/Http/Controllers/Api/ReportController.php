<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OtSessionEmployee;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\PayrollSlip;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /* =================== PAYROLL =================== */

    public function payrollSummary(Request $request): JsonResponse
    {
        $periodId = $request->integer('period_id');
        $period = $periodId
            ? PayrollPeriod::find($periodId)
            : PayrollPeriod::orderByDesc('start_date')->first();

        if (! $period) {
            return response()->json(['data' => null, 'message' => 'ยังไม่มีงวดเงินเดือน']);
        }

        $slips = PayrollSlip::with('employee.department')
            ->where('payroll_period_id', $period->id)
            ->get();

        $totals = [
            'employees'         => $slips->count(),
            'base_pay'          => round($slips->sum('base_pay'), 2),
            'ot_pay'            => round($slips->sum('ot_pay'), 2),
            'allowances_total'  => round($slips->sum('allowances_total'), 2),
            'bonus_total'       => round($slips->sum('bonus_total'), 2),
            'gross_pay'         => round($slips->sum('gross_pay'), 2),
            'deductions_total'  => round($slips->sum('deductions_total'), 2),
            'late_deduction'    => round($slips->sum('late_deduction'), 2),
            'absent_deduction'  => round($slips->sum('absent_deduction'), 2),
            'ssf_employee'      => round($slips->sum('ssf_employee'), 2),
            'tax'               => round($slips->sum('tax'), 2),
            'net_pay'           => round($slips->sum('net_pay'), 2),
        ];

        $byDepartment = $slips->groupBy(fn ($s) => optional($s->employee?->department)->name ?? 'ไม่ระบุ')
            ->map(fn ($g, $k) => [
                'department' => $k,
                'employees'  => $g->count(),
                'gross_pay'  => round($g->sum('gross_pay'), 2),
                'net_pay'    => round($g->sum('net_pay'), 2),
                'deductions' => round($g->sum('deductions_total'), 2),
            ])->values();

        $byEmployee = $slips->map(fn ($s) => [
            'slip_id'         => $s->id,
            'employee_code'   => $s->employee?->employee_code,
            'employee_name'   => trim(($s->employee?->first_name ?? '') . ' ' . ($s->employee?->last_name ?? '')),
            'department'      => optional($s->employee?->department)->name,
            'base_salary'     => (float) $s->base_salary,
            'ot_pay'          => (float) $s->ot_pay,
            'bonus_total'     => (float) $s->bonus_total,
            'late_deduction'  => (float) $s->late_deduction,
            'absent_deduction'=> (float) $s->absent_deduction,
            'other_deductions'=> (float) $s->other_deductions_total,
            'deductions_total'=> (float) $s->deductions_total,
            'tax'             => (float) $s->tax,
            'ssf'             => (float) $s->ssf_employee,
            'gross_pay'       => (float) $s->gross_pay,
            'net_pay'         => (float) $s->net_pay,
            'status'          => $s->status,
        ])->values();

        return response()->json([
            'data' => [
                'period'         => $period->only(['id', 'code', 'name', 'start_date', 'end_date', 'status']),
                'totals'         => $totals,
                'by_department'  => $byDepartment,
                'by_employee'    => $byEmployee,
            ],
        ]);
    }

    public function payrollPeriods(): JsonResponse
    {
        return response()->json([
            'data' => PayrollPeriod::orderByDesc('start_date')
                ->limit(24)
                ->get(['id', 'code', 'name', 'start_date', 'end_date', 'status']),
        ]);
    }

    public function payrollExport(Request $request): StreamedResponse
    {
        $period = PayrollPeriod::findOrFail($request->integer('period_id'));
        $slips = PayrollSlip::with('employee.department')
            ->where('payroll_period_id', $period->id)
            ->orderBy('employee_id')
            ->get();

        $filename = "payroll-{$period->code}.csv";
        $headers = [
            'รหัสพนักงาน', 'ชื่อ-นามสกุล', 'แผนก',
            'เงินเดือนฐาน', 'OT', 'เบี้ย/โบนัส',
            'หักสาย', 'หักขาด', 'หักอื่น ๆ',
            'รวมหัก', 'ภาษี', 'SSF',
            'รายได้รวม', 'สุทธิ', 'สถานะ',
        ];

        return $this->csvStream($filename, $headers, $slips->map(fn ($s) => [
            $s->employee?->employee_code,
            trim(($s->employee?->first_name ?? '') . ' ' . ($s->employee?->last_name ?? '')),
            optional($s->employee?->department)->name,
            $s->base_salary, $s->ot_pay, $s->bonus_total,
            $s->late_deduction, $s->absent_deduction, $s->other_deductions_total,
            $s->deductions_total, $s->tax, $s->ssf_employee,
            $s->gross_pay, $s->net_pay, $s->status,
        ]));
    }

    /* =================== ATTENDANCE =================== */

    public function attendanceSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);
        $deptId = $request->integer('department_id');

        $empQuery = Employee::query()->where('status', Employee::STATUS_ACTIVE);
        if ($deptId) {
            $empQuery->where('department_id', $deptId);
        }
        $employees = $empQuery->with('department')->get();
        $employeeIds = $employees->pluck('id');

        $rows = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('checked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        $totalDays = (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())) + 1;
        $totalLate = $rows->filter(fn ($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0)
            ->groupBy(fn ($r) => $r->employee_id . '|' . $r->checked_at->toDateString())->count();
        $totalLateMinutes = (int) $rows->sum('late_minutes');

        // Daily trend
        $dailyMap = $rows->groupBy(fn ($r) => $r->checked_at->toDateString());
        $daily = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $dayRows = $dailyMap->get($key, collect());
            $checkIns = $dayRows->where('type', 'check_in');
            $lateRows = $checkIns->filter(fn ($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0);
            $daily[] = [
                'date'         => $key,
                'present'      => $checkIns->pluck('employee_id')->unique()->count(),
                'late_count'   => $lateRows->pluck('employee_id')->unique()->count(),
                'late_minutes' => (int) $checkIns->sum('late_minutes'),
            ];
        }

        // Per-employee — full roster
        $rowsByEmp = $rows->groupBy('employee_id');
        $byEmployee = $employees->map(function ($emp) use ($rowsByEmp, $totalDays) {
            $g = $rowsByEmp->get($emp->id, collect());
            $checkIns = $g->where('type', 'check_in');
            $presentDays = $checkIns->groupBy(fn ($r) => $r->checked_at->toDateString())->count();
            $lateRows = $checkIns->filter(fn ($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0);
            $lateDays = $lateRows->groupBy(fn ($r) => $r->checked_at->toDateString())->count();
            $lateMinutes = (int) $checkIns->sum('late_minutes');
            return [
                'employee_id'     => $emp->id,
                'employee_code'   => $emp->employee_code,
                'employee_name'   => trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? '')),
                'department'      => optional($emp->department)->name,
                'present_days'    => $presentDays,
                'absent_days'     => max(0, $totalDays - $presentDays),
                'late_days'       => $lateDays,
                'late_minutes'    => $lateMinutes,
                'attendance_rate' => $totalDays > 0 ? round($presentDays / $totalDays * 100, 2) : 0,
            ];
        })->sortBy('employee_code')->values();

        return response()->json([
            'data' => [
                'range'  => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $totalDays],
                'totals' => [
                    'employees'    => $employees->count(),
                    'present_days' => $rows->where('type', 'check_in')
                        ->groupBy(fn ($r) => $r->employee_id . '|' . $r->checked_at->toDateString())->count(),
                    'late_days'    => $totalLate,
                    'late_minutes' => $totalLateMinutes,
                ],
                'daily_trend' => $daily,
                'by_employee' => $byEmployee,
            ],
        ]);
    }

    public function attendanceExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseRange($request);
        $rows = Attendance::with('employee.department')
            ->whereBetween('checked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('checked_at')
            ->get();

        $headers = ['วันที่-เวลา', 'รหัสพนักงาน', 'ชื่อ-นามสกุล', 'แผนก', 'ประเภท', 'สถานะ', 'สาย (นาที)'];
        return $this->csvStream("attendance-{$from->toDateString()}-{$to->toDateString()}.csv", $headers, $rows->map(fn ($r) => [
            $r->checked_at->format('Y-m-d H:i'),
            $r->employee?->employee_code,
            trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->last_name ?? '')),
            optional($r->employee?->department)->name,
            $r->type,
            $r->status,
            $r->late_minutes ?? 0,
        ]));
    }

    /* =================== LEAVE =================== */

    public function leaveSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);
        $deptId = $request->integer('department_id');

        $query = LeaveRequest::with(['employee.department', 'leaveType'])
            ->whereBetween('start_date', [$from, $to])
            ->where('status', LeaveRequest::STATUS_APPROVED);

        if ($deptId) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $deptId));
        }
        $rows = $query->get();

        $leaveTypes = $rows->pluck('leaveType.name')->filter()->unique()->values();

        $byEmployee = $rows->groupBy('employee_id')
            ->map(function ($g) use ($leaveTypes) {
                $emp = $g->first()->employee;
                $byType = [];
                foreach ($leaveTypes as $t) {
                    $byType[$t] = round($g->where('leaveType.name', $t)->sum('total_days'), 2);
                }
                return [
                    'employee_id'   => $emp?->id,
                    'employee_code' => $emp?->employee_code,
                    'employee_name' => trim(($emp?->first_name ?? '') . ' ' . ($emp?->last_name ?? '')),
                    'department'    => optional($emp?->department)->name,
                    'requests'      => $g->count(),
                    'total_days'    => round($g->sum('total_days'), 2),
                    'by_type'       => $byType,
                ];
            })
            ->sortByDesc('total_days')
            ->values();

        $byType = $rows->groupBy(fn ($r) => $r->leaveType?->name ?? 'ไม่ระบุ')
            ->map(fn ($g, $k) => [
                'leave_type' => $k,
                'count'      => $g->count(),
                'total_days' => round($g->sum('total_days'), 2),
            ])->values();

        return response()->json([
            'data' => [
                'range'   => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals'  => [
                    'requests'   => $rows->count(),
                    'total_days' => round($rows->sum('total_days'), 2),
                    'employees'  => $byEmployee->count(),
                ],
                'leave_types'  => $leaveTypes,
                'by_type'      => $byType,
                'by_employee'  => $byEmployee,
            ],
        ]);
    }

    /* =================== EMPLOYEES / HR =================== */

    public function employeesSummary(Request $request): JsonResponse
    {
        $employees = Employee::with(['department', 'employmentType'])->get();

        $byStatus = $employees->groupBy('status')
            ->map(fn ($g, $k) => ['status' => $k, 'count' => $g->count()])
            ->values();

        $byDept = $employees->where('status', Employee::STATUS_ACTIVE)
            ->groupBy(fn ($e) => optional($e->department)->name ?? 'ไม่ระบุ')
            ->map(fn ($g, $k) => ['department' => $k, 'count' => $g->count()])
            ->sortByDesc('count')->values();

        $byEmpType = $employees->where('status', Employee::STATUS_ACTIVE)
            ->groupBy(fn ($e) => optional($e->employmentType)->name ?? 'ไม่ระบุ')
            ->map(fn ($g, $k) => ['employment_type' => $k, 'count' => $g->count()])
            ->values();

        // Tenure & turnover
        $now = Carbon::now();
        $active = $employees->where('status', Employee::STATUS_ACTIVE);
        $avgTenureYears = $active->avg(fn ($e) => $e->hire_date ? Carbon::parse($e->hire_date)->diffInDays($now) / 365.25 : 0);

        // 12-month turnover trend
        $monthlyTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $hires = $employees->filter(fn ($e) => $e->hire_date && Carbon::parse($e->hire_date)->between($monthStart, $monthEnd))->count();
            $resigns = $employees->filter(fn ($e) => $e->resign_date && Carbon::parse($e->resign_date)->between($monthStart, $monthEnd))->count();
            $monthlyTrend[] = [
                'month'   => $monthStart->format('Y-m'),
                'hires'   => $hires,
                'resigns' => $resigns,
            ];
        }

        return response()->json([
            'data' => [
                'totals' => [
                    'total'    => $employees->count(),
                    'active'   => $active->count(),
                    'resigned' => $employees->where('status', '!=', Employee::STATUS_ACTIVE)->count(),
                    'avg_tenure_years' => round((float) $avgTenureYears, 2),
                ],
                'by_status'      => $byStatus,
                'by_department'  => $byDept,
                'by_employment_type' => $byEmpType,
                'monthly_trend'  => $monthlyTrend,
                'by_employee'    => $employees->map(fn ($e) => [
                    'employee_id'    => $e->id,
                    'employee_code'  => $e->employee_code,
                    'employee_name'  => trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')),
                    'department'     => optional($e->department)->name,
                    'employment_type'=> optional($e->employmentType)->name,
                    'status'         => $e->status,
                    'hire_date'      => $e->hire_date ? Carbon::parse($e->hire_date)->toDateString() : null,
                    'resign_date'    => $e->resign_date ? Carbon::parse($e->resign_date)->toDateString() : null,
                    'tenure_years'   => $e->hire_date
                        ? round(Carbon::parse($e->hire_date)->diffInDays($now) / 365.25, 2)
                        : 0,
                ])->sortBy('employee_code')->values(),
            ],
        ]);
    }

    /* =================== OT (รวมในรายงานเงินเดือน + standalone) =================== */

    public function otSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);

        $rows = OtSessionEmployee::with(['employee.department', 'session'])
            ->whereHas('session', fn ($q) => $q->whereBetween('ot_date', [$from, $to]))
            ->get();

        $totals = [
            'records'      => $rows->count(),
            'total_hours'  => round($rows->sum('hours'), 2),
            'total_amount' => round($rows->sum('total_amount'), 2),
        ];

        $byEmployee = $rows->groupBy('employee_id')
            ->map(function ($g) {
                $emp = $g->first()->employee;
                return [
                    'employee_id'   => $emp?->id,
                    'employee_code' => $emp?->employee_code,
                    'employee_name' => trim(($emp?->first_name ?? '') . ' ' . ($emp?->last_name ?? '')),
                    'department'    => optional($emp?->department)->name,
                    'sessions'      => $g->count(),
                    'hours'         => round($g->sum('hours'), 2),
                    'amount'        => round($g->sum('total_amount'), 2),
                ];
            })
            ->sortByDesc('hours')->values();

        return response()->json([
            'data' => [
                'range'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals' => $totals,
                'by_employee' => $byEmployee,
            ],
        ]);
    }

    /* =================== TASKS =================== */

    public function tasksSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);

        $tasks = Task::with('assignees.employee.department')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $byStatus = $tasks->groupBy('status')
            ->map(fn ($g, $k) => ['status' => $k, 'count' => $g->count()])
            ->values();

        $byPriority = $tasks->groupBy('priority')
            ->map(fn ($g, $k) => ['priority' => $k ?: 'normal', 'count' => $g->count()])
            ->values();

        $assignees = $tasks->flatMap->assignees;
        $rated = $assignees->whereNotNull('rating');

        $byEmployee = $assignees->groupBy('employee_id')
            ->map(function ($g) {
                $emp = $g->first()->employee;
                $ratings = $g->whereNotNull('rating');
                return [
                    'employee_id'   => $emp?->id,
                    'employee_code' => $emp?->employee_code,
                    'employee_name' => trim(($emp?->first_name ?? '') . ' ' . ($emp?->last_name ?? '')),
                    'department'    => optional($emp?->department)->name,
                    'tasks'         => $g->count(),
                    'completed'     => $g->where('status', 'completed')->count(),
                    'in_progress'   => $g->where('status', 'in_progress')->count(),
                    'rejected'      => $g->where('status', 'rejected')->count(),
                    'avg_rating'    => $ratings->count() ? round($ratings->avg('rating'), 2) : null,
                ];
            })
            ->sortByDesc('tasks')
            ->values();

        return response()->json([
            'data' => [
                'range'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals' => [
                    'tasks'      => $tasks->count(),
                    'completed'  => $tasks->where('status', 'completed')->count(),
                    'in_progress'=> $tasks->where('status', 'in_progress')->count(),
                    'avg_rating' => $rated->count() ? round($rated->avg('rating'), 2) : null,
                ],
                'by_status'   => $byStatus,
                'by_priority' => $byPriority,
                'by_employee' => $byEmployee,
            ],
        ]);
    }

    /* =================== EXPORTS (CSV) =================== */

    public function leaveExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseRange($request);
        $deptId = $request->integer('department_id');

        $query = LeaveRequest::with(['employee.department', 'leaveType'])
            ->whereBetween('start_date', [$from, $to])
            ->where('status', LeaveRequest::STATUS_APPROVED);
        if ($deptId) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $deptId));
        }
        $rows = $query->get();

        $headers = ['รหัสพนักงาน', 'ชื่อ-นามสกุล', 'แผนก', 'ประเภทการลา', 'วันเริ่ม', 'วันสิ้นสุด', 'จำนวนวัน', 'เหตุผล'];

        return $this->csvStream(
            "leave-{$from->toDateString()}-{$to->toDateString()}.csv",
            $headers,
            $rows->map(fn ($r) => [
                $r->employee?->employee_code,
                trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->last_name ?? '')),
                optional($r->employee?->department)->name,
                $r->leaveType?->name,
                $r->start_date ? Carbon::parse($r->start_date)->toDateString() : null,
                $r->end_date ? Carbon::parse($r->end_date)->toDateString() : null,
                $r->total_days,
                $r->reason,
            ])
        );
    }

    public function otExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseRange($request);

        $rows = OtSessionEmployee::with(['employee.department', 'session'])
            ->whereHas('session', fn ($q) => $q->whereBetween('ot_date', [$from, $to]))
            ->get();

        $headers = ['รหัสพนักงาน', 'ชื่อ-นามสกุล', 'แผนก', 'วันที่ OT', 'ชั่วโมง', 'อัตรา', 'จำนวนเงิน'];

        return $this->csvStream(
            "ot-{$from->toDateString()}-{$to->toDateString()}.csv",
            $headers,
            $rows->map(fn ($r) => [
                $r->employee?->employee_code,
                trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->last_name ?? '')),
                optional($r->employee?->department)->name,
                $r->session?->ot_date ? Carbon::parse($r->session->ot_date)->toDateString() : null,
                $r->hours,
                $r->rate ?? null,
                $r->total_amount,
            ])
        );
    }

    public function tasksExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseRange($request);

        $tasks = Task::with('assignees.employee.department')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $assignees = $tasks->flatMap->assignees;

        $byEmployee = $assignees->groupBy('employee_id')->map(function ($g) {
            $emp = $g->first()->employee;
            $ratings = $g->whereNotNull('rating');
            return [
                'code'        => $emp?->employee_code,
                'name'        => trim(($emp?->first_name ?? '') . ' ' . ($emp?->last_name ?? '')),
                'department'  => optional($emp?->department)->name,
                'tasks'       => $g->count(),
                'completed'   => $g->where('status', 'completed')->count(),
                'in_progress' => $g->where('status', 'in_progress')->count(),
                'rejected'    => $g->where('status', 'rejected')->count(),
                'avg_rating'  => $ratings->count() ? round($ratings->avg('rating'), 2) : null,
            ];
        })->sortByDesc('tasks')->values();

        $headers = ['รหัสพนักงาน', 'ชื่อ-นามสกุล', 'แผนก', 'งานทั้งหมด', 'เสร็จ', 'กำลังทำ', 'ปฏิเสธ', 'คะแนนเฉลี่ย'];

        return $this->csvStream(
            "tasks-{$from->toDateString()}-{$to->toDateString()}.csv",
            $headers,
            $byEmployee->map(fn ($r) => [
                $r['code'], $r['name'], $r['department'],
                $r['tasks'], $r['completed'], $r['in_progress'], $r['rejected'],
                $r['avg_rating'],
            ])
        );
    }

    public function employeesExport(Request $request): StreamedResponse
    {
        $now = Carbon::now();
        $employees = Employee::with(['department', 'employmentType'])
            ->orderBy('employee_code')
            ->get();

        $headers = ['รหัสพนักงาน', 'ชื่อ-นามสกุล', 'แผนก', 'ประเภทการจ้าง', 'สถานะ', 'วันเริ่มงาน', 'วันลาออก', 'อายุงาน (ปี)'];

        return $this->csvStream(
            'employees-' . $now->toDateString() . '.csv',
            $headers,
            $employees->map(fn ($e) => [
                $e->employee_code,
                trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')),
                optional($e->department)->name,
                optional($e->employmentType)->name,
                $e->status,
                $e->hire_date ? Carbon::parse($e->hire_date)->toDateString() : null,
                $e->resign_date ? Carbon::parse($e->resign_date)->toDateString() : null,
                $e->hire_date ? round(Carbon::parse($e->hire_date)->diffInDays($now) / 365.25, 2) : 0,
            ])
        );
    }

    /* =================== PAYSLIP (single) =================== */

    public function payslipShow(int $slipId): JsonResponse
    {
        $slip = PayrollSlip::with(['employee.department', 'employee.employmentType', 'period', 'items'])
            ->findOrFail($slipId);

        return response()->json(['data' => $this->buildPayslipDto($slip)]);
    }

    /**
     * GET /reports/payslips?period_id=X — payslip ทั้งงวด (สำหรับพิมพ์รวบเดียว)
     */
    public function payslipsByPeriod(Request $request): JsonResponse
    {
        $slips = PayrollSlip::with(['employee.department', 'employee.employmentType', 'period', 'items'])
            ->when($request->integer('period_id'), fn ($q, $pid) => $q->where('payroll_period_id', $pid))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->get()
            ->sortBy(fn ($s) => $s->employee?->employee_code)
            ->values();

        return response()->json(['data' => $slips->map(fn ($s) => $this->buildPayslipDto($s))->values()]);
    }

    protected function buildPayslipDto(PayrollSlip $slip): array
    {
        return [
            'slip_id'   => $slip->id,
            'slip_no'   => $slip->slip_no,
            'status'    => $slip->status,
            'company'   => $this->payslipCompany(),
            'period'    => [
                'id'         => $slip->period?->id,
                'code'       => $slip->period?->code,
                'name'       => $slip->period?->name,
                'start_date' => $slip->period?->start_date,
                'end_date'   => $slip->period?->end_date,
                'pay_date'   => $slip->period?->pay_date,
            ],
            'employee'  => [
                'id'             => $slip->employee?->id,
                'employee_code'  => $slip->employee?->employee_code,
                'employee_name'  => trim(($slip->employee?->first_name ?? '') . ' ' . ($slip->employee?->last_name ?? '')),
                'department'     => optional($slip->employee?->department)->name,
                'employment_type'=> optional($slip->employee?->employmentType)->name,
                'hire_date'      => $slip->employee?->hire_date,
                'bank_name'      => $slip->employee?->bank_name,
                'bank_account_no'=> $slip->employee?->bank_account_no,
            ],
            'meta'      => [
                'present_days'   => (int) $slip->present_days,
                'working_days'   => (int) $slip->working_days,
                'daily_rate'     => (float) $slip->daily_rate,
                'ot_hours_total' => (float) $slip->ot_hours_total,
            ],
            'earnings' => [
                'base_salary'      => (float) $slip->base_salary,
                'base_pay'         => (float) $slip->base_pay,
                'ot_pay'           => (float) $slip->ot_pay,
                'allowances_total' => (float) $slip->allowances_total,
                'bonus_total'      => (float) $slip->bonus_total,
                'gross_pay'        => (float) $slip->gross_pay,
            ],
            'deductions' => [
                'late_deduction'       => (float) $slip->late_deduction,
                'absent_deduction'     => (float) $slip->absent_deduction,
                'other_deductions_total' => (float) $slip->other_deductions_total,
                'tax'                  => (float) $slip->tax,
                'ssf_employee'         => (float) $slip->ssf_employee,
                'deductions_total'     => (float) $slip->deductions_total,
            ],
            'items' => $slip->items
                ->sortBy('order')
                ->map(fn ($it) => [
                    'type'     => $it->type,
                    'source'   => $it->source,
                    'code'     => $it->code,
                    'name'     => $it->name,
                    'amount'   => (float) $it->amount,
                    'quantity' => $it->quantity !== null ? (float) $it->quantity : null,
                    'rate'     => $it->rate !== null ? (float) $it->rate : null,
                ])->values(),
            'net_pay'  => (float) $slip->net_pay,
        ];
    }

    /** หัวกระดาษสลิป (ชื่อ/ที่อยู่บริษัท) — อ่านจาก payroll_settings, memoize ต่อ request */
    protected ?array $payslipCompanyCache = null;

    protected function payslipCompany(): array
    {
        return $this->payslipCompanyCache ??= [
            'name'    => (string) (PayrollSetting::get('company_name') ?: 'บริษัท ชาญเจริญคอนกรีต จำกัด'),
            'address' => (string) (PayrollSetting::get('company_address') ?: ''),
        ];
    }

    /* =================== helpers =================== */

    protected function parseRange(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : Carbon::now()->endOfMonth();
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    protected function csvStream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->stream(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : (array) $row);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

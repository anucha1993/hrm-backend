<?php

namespace App\Http\Controllers\Api;

use App\Exports\AttendanceHistoryExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OfficeLocation;
use App\Models\WorkShift;
use App\Services\WorkScheduleService;
use App\Support\HipTimeAttendanceWindow;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceController extends Controller
{
    /** timezone ของธุรกิจ — checked_at เก็บใน DB เป็น UTC แต่คำนวณสาย/OT บนเวลาท้องถิ่น */
    private const TZ = 'Asia/Bangkok';

    public function __construct(private readonly WorkScheduleService $schedule)
    {
    }

    /** ลงเวลา (เข้า/ออก) */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'       => ['required', 'in:check_in,check_out'],
            'latitude'   => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'  => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'photo'      => ['required', 'file', 'image', 'max:8192'], // 8MB
            'note'       => ['nullable', 'string', 'max:500'],
        ]);

        $employee = $this->resolveEmployee($request);
        if (! $employee) {
            return response()->json(['message' => 'ไม่พบข้อมูลพนักงานที่เชื่อมกับบัญชีนี้'], 422);
        }

        $now = Carbon::now();

        // ป้องกันการลงเวลาซ้ำภายใน 1 นาที
        $recent = Attendance::where('employee_id', $employee->id)
            ->where('type', $data['type'])
            ->where('checked_at', '>=', $now->copy()->subMinute())
            ->exists();
        if ($recent) {
            return response()->json(['message' => 'ลงเวลาเพิ่งบันทึกไปแล้ว กรุณารอสักครู่'], 422);
        }

        // หา office_location ใกล้สุด + คำนวณ geofence
        $office = null;
        $distance = null;
        $outside = false;
        if (! empty($data['latitude']) && ! empty($data['longitude'])) {
            $office = OfficeLocation::where('is_active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get()
                ->map(function (OfficeLocation $o) use ($data) {
                    $o->_distance = $o->distanceFrom($data['latitude'], $data['longitude']);
                    return $o;
                })
                ->sortBy('_distance')
                ->first();

            if ($office) {
                $distance = round($office->_distance, 2);
                if ($office->enforce_geofence && $distance > $office->radius_m) {
                    $outside = true;
                }
            }
        }

        // หากะที่ active ของพนักงาน → คำนวณสาย (เทียบบนเวลาท้องถิ่น)
        $shift = $this->resolveShift($employee, $now);
        $status = 'normal';
        $lateMinutes = null;
        $local = $now->copy()->setTimezone(self::TZ);
        $isHoliday = $this->schedule->isHoliday($employee, $local);
        if (! $isHoliday && $shift && $data['type'] === 'check_in') {
            $shiftStart = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->start_time, self::TZ);
            $diff = $local->diffInMinutes($shiftStart, false); // negative = late
            if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                $status = 'late';
                $lateMinutes = abs($diff);
            }
        } elseif (! $isHoliday && $shift && $data['type'] === 'check_out') {
            $shiftEnd = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->end_time, self::TZ);
            if ($local->lt($shiftEnd)) $status = 'early_leave';
            elseif ($local->gt($shiftEnd->copy()->addMinutes(15)) && $this->schedule->allowsOt($employee)) $status = 'overtime';
        }

        // เก็บรูป
        $photoPath = $request->file('photo')->store(
            "attendance/{$employee->id}/" . $now->format('Y-m'),
            'public'
        );

        $attendance = Attendance::create([
            'employee_id'        => $employee->id,
            'type'               => $data['type'],
            'checked_at'         => $now,
            'latitude'           => $data['latitude'] ?? null,
            'longitude'          => $data['longitude'] ?? null,
            'accuracy_m'         => $data['accuracy_m'] ?? null,
            'office_location_id' => $office?->id,
            'distance_m'         => $distance,
            'outside_geofence'   => $outside,
            'work_shift_id'      => $shift?->id,
            'status'             => $status,
            'late_minutes'       => $lateMinutes,
            'photo_path'         => $photoPath,
            'note'               => $data['note'] ?? null,
        ]);

        return response()->json([
            'data' => $attendance->load(['officeLocation', 'workShift']),
            'message' => $this->buildMessage($data['type'], $status, $outside),
        ], 201);
    }

    /** ประวัติของฉัน */
    public function myHistory(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        if (! $employee) return response()->json(['data' => [], 'message' => 'no employee']);

        $q = Attendance::with(['officeLocation', 'workShift'])
            ->where('employee_id', $employee->id)
            ->orderBy('checked_at', 'desc');

        if ($from = $request->date('from')) $q->where('checked_at', '>=', $from);
        if ($to   = $request->date('to'))   $q->where('checked_at', '<=', $to->endOfDay());

        return response()->json(['data' => $q->paginate($request->integer('per_page', 30))]);
    }

    /** ลงเวลาล่าสุดวันนี้ของฉัน */
    public function todayStatus(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        if (! $employee) {
            return response()->json([
                'has_employee'  => false,
                'last_check_in' => null,
                'last_check_out'=> null,
                'shift'         => null,
            ]);
        }

        $today = Carbon::today();
        $checkIn  = Attendance::where('employee_id', $employee->id)->where('type', 'check_in')
            ->whereDate('checked_at', $today)->latest('checked_at')->first();
        $checkOut = Attendance::where('employee_id', $employee->id)->where('type', 'check_out')
            ->whereDate('checked_at', $today)->latest('checked_at')->first();

        return response()->json([
            'has_employee'   => true,
            'employee'       => $employee->only(['id', 'employee_code', 'first_name', 'last_name']),
            'last_check_in'  => $checkIn ? $checkIn->load('officeLocation') : null,
            'last_check_out' => $checkOut ? $checkOut->load('officeLocation') : null,
            'shift'          => $this->resolveShift($employee, Carbon::now()),
            'office_locations' => OfficeLocation::where('is_active', true)->get(),
        ]);
    }

    /** ดูประวัติทั้งหมด (สำหรับ admin) */
    public function index(Request $request): JsonResponse
    {
        $q = Attendance::with(['employee:id,employee_code,first_name,last_name,department_id', 'employee.department:id,code,name', 'officeLocation'])
            ->orderBy('checked_at', 'desc');

        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
        if ($deptId = $request->integer('department_id')) $q->whereHas('employee', fn ($w) => $w->where('department_id', $deptId));
        if ($type = $request->string('type')->toString()) $q->where('type', $type);
        if ($from = $request->date('from')) $q->where('checked_at', '>=', $from);
        if ($to   = $request->date('to'))   $q->where('checked_at', '<=', $to->endOfDay());

        // เครื่องสแกน HIP Time อาจสแกนหลายรอบต่อวัน (เช่น เปิดประตู) — ข้อมูลดิบเก็บไว้ทุก record
        // แต่แสดงผลแค่ 1 record ที่ดีที่สุดต่อวัน/ประเภท (manual entry ไม่ถูกกรอง)
        $q->where(fn ($w) => $w->where('source', '!=', 'device')->orWhereIn('id', $this->bestDeviceAttendanceIds($request)));

        return response()->json(['data' => $q->paginate($request->integer('per_page', 30))]);
    }

    /**
     * หา id ของ record ฝั่งเครื่อง HIP Time ที่ "ดีที่สุด" ต่อ employee/ประเภท/วันงาน
     * (เข้างาน = เวลาเช้าที่สุด, ออกงาน = เวลาล่าสุด) ให้ index()/export() ใช้กรองไม่ให้เห็น record ซ้ำ
     */
    private function bestDeviceAttendanceIds(Request $request): array
    {
        $q = Attendance::where('source', 'device')->select('id', 'employee_id', 'type', 'checked_at');

        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
        if ($type = $request->string('type')->toString()) $q->where('type', $type);
        // ขยายช่วงวันที่ ±1 วัน กันเคสสแกนออกดึกข้ามเที่ยงคืนหลุดขอบเขตของ work_date bucket
        if ($from = $request->date('from')) $q->where('checked_at', '>=', $from->copy()->subDay());
        if ($to   = $request->date('to'))   $q->where('checked_at', '<=', $to->copy()->addDay()->endOfDay());

        $best = [];
        foreach ($q->get() as $row) {
            $local = $row->checked_at->copy()->setTimezone(self::TZ);
            $workDate = HipTimeAttendanceWindow::workDateFor($row->type, $local);
            $key = $row->employee_id . '|' . $row->type . '|' . $workDate;

            if (! isset($best[$key])) {
                $best[$key] = $row;
                continue;
            }

            $isBetter = $row->type === 'check_in'
                ? $row->checked_at->lt($best[$key]->checked_at)
                : $row->checked_at->gt($best[$key]->checked_at);

            if ($isBetter) $best[$key] = $row;
        }

        return array_map(fn ($r) => $r->id, $best);
    }

    /**
     * GET /attendance/roster?date=YYYY-MM-DD&department_id=&employee_id=
     * แสดงรายชื่อพนักงานทั้งหมด "1 คน 1 record ต่อวัน" รวมเข้างาน/ออกงาน/สถานะ (ลา/ขาด/วันหยุด) ไว้ในแถวเดียว
     * ใช้แทนการแสดงรายการ Attendance ดิบ (ซึ่งอาจมี 2 record ต่อคนต่อวัน แยกเข้า/ออก)
     */
    public function roster(Request $request): JsonResponse
    {
        $date = $request->date('date') ?: Carbon::today(self::TZ);
        $dateStr = $date->toDateString();

        $employees = Employee::query()
            ->where('status', Employee::STATUS_ACTIVE)
            ->when($request->integer('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->integer('employee_id'), fn ($q, $v) => $q->where('id', $v))
            ->with('department:id,code,name,attendance_mode')
            ->orderBy('employee_code')
            ->get();

        if ($employees->isEmpty()) {
            return response()->json(['data' => ['date' => $dateStr, 'rows' => []]]);
        }

        $employeeIds = $employees->pluck('id');

        // ขยายช่วง ±1 วัน กันเคสออกงานข้ามเที่ยงคืนหลุดขอบเขต work_date bucket
        $windowStart = Carbon::parse($dateStr, self::TZ)->subDay()->startOfDay()->utc();
        $windowEnd = Carbon::parse($dateStr, self::TZ)->addDay()->endOfDay()->utc();

        $attendances = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('checked_at', [$windowStart, $windowEnd])
            ->orderBy('checked_at')
            ->get();

        // จัดกลุ่มตาม employee_id|type|work_date แล้วเลือก record ที่ "ดีที่สุด" ต่อกลุ่ม
        // (manual entry มีสิทธิ์เหนือกว่า device เสมอ, ถ้าเป็น device ล้วนใช้เวลาที่เช้าสุด/ล่าสุดตามกติกาเดิม)
        $buckets = [];
        foreach ($attendances as $row) {
            $local = $row->checked_at->copy()->setTimezone(self::TZ);
            $workDate = HipTimeAttendanceWindow::workDateFor($row->type, $local);
            if ($workDate !== $dateStr) {
                continue;
            }
            $key = $row->employee_id . '|' . $row->type;
            $buckets[$key][] = $row;
        }

        $best = [];
        foreach ($buckets as $key => $rows) {
            $manual = array_values(array_filter($rows, fn ($r) => $r->source !== 'device'));
            $pool = $manual ?: $rows;
            $type = $pool[0]->type;
            $winner = $pool[0];
            foreach ($pool as $row) {
                $isBetter = $type === 'check_in'
                    ? $row->checked_at->lt($winner->checked_at)
                    : $row->checked_at->gt($winner->checked_at);
                if ($isBetter) $winner = $row;
            }
            $best[$key] = $winner;
        }

        $leaves = LeaveRequest::with('leaveType:id,name')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->get()
            ->keyBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($best, $leaves, $date, $dateStr) {
            $checkIn = $best[$employee->id . '|check_in'] ?? null;
            $checkOut = $best[$employee->id . '|check_out'] ?? null;
            $leave = $leaves->get($employee->id);

            $shift = $this->schedule->resolveShift($employee, $date);
            $isHoliday = $this->schedule->isHoliday($employee, $date);
            $mode = $employee->department?->attendance_mode ?? \App\Models\Department::ATTENDANCE_FULL;

            if ($mode === \App\Models\Department::ATTENDANCE_NONE) {
                // งานเหมา — ไม่บันทึกเวลา, ไม่ถือว่าขาดงาน
                $dayStatus = 'no_track';
            } elseif ($leave) {
                $dayStatus = 'leave';
            } elseif ($isHoliday) {
                $dayStatus = 'holiday';
            } elseif (! $shift) {
                $dayStatus = 'day_off';
            } elseif ($checkIn) {
                $dayStatus = $checkIn->status ?? 'normal';
            } elseif ($mode === \App\Models\Department::ATTENDANCE_CHECK_IN_ONLY) {
                // แผนกสแกนเข้าอย่างเดียว — ไม่มี checkIn เท่านั้นจึงจะถือว่าขาด
                $dayStatus = $dateStr <= Carbon::today(self::TZ)->toDateString() ? 'absent' : 'upcoming';
            } elseif ($checkOut) {
                $dayStatus = $checkOut->status ?? 'normal';
            } elseif ($dateStr <= Carbon::today(self::TZ)->toDateString()) {
                $dayStatus = 'absent';
            } else {
                $dayStatus = 'upcoming';
            }

            return [
                'employee' => [
                    'id'            => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'first_name'    => $employee->first_name,
                    'last_name'     => $employee->last_name,
                    'department'    => $employee->department ? ['id' => $employee->department->id, 'name' => $employee->department->name] : null,
                ],
                'date'        => $dateStr,
                'day_status'  => $dayStatus,
                'check_in'    => $checkIn ? [
                    'id' => $checkIn->id, 'checked_at' => $checkIn->checked_at, 'status' => $checkIn->status,
                    'late_minutes' => $checkIn->late_minutes, 'source' => $checkIn->source,
                ] : null,
                'check_out'   => $checkOut ? [
                    'id' => $checkOut->id, 'checked_at' => $checkOut->checked_at, 'status' => $checkOut->status,
                    'late_minutes' => $checkOut->late_minutes, 'source' => $checkOut->source,
                ] : null,
                'leave'       => $leave ? [
                    'id' => $leave->id, 'type' => $leave->leaveType->name ?? '-', 'is_half_day' => $leave->is_half_day,
                ] : null,
                'shift'       => $shift ? ['id' => $shift->id, 'name' => $shift->name, 'start_time' => $shift->start_time, 'end_time' => $shift->end_time] : null,
            ];
        });

        return response()->json(['data' => ['date' => $dateStr, 'rows' => $rows->values()]]);
    }

    /**
     * GET /attendance/export — Excel
     */
    public function export(Request $request): BinaryFileResponse
    {
        $q = Attendance::with(['employee:id,employee_code,first_name,last_name,department_id', 'employee.department:id,code,name', 'officeLocation'])
            ->orderBy('checked_at', 'desc');
        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
        if ($deptId = $request->integer('department_id')) $q->whereHas('employee', fn ($w) => $w->where('department_id', $deptId));
        if ($type = $request->string('type')->toString()) $q->where('type', $type);
        if ($from = $request->date('from')) $q->where('checked_at', '>=', $from);
        if ($to   = $request->date('to'))   $q->where('checked_at', '<=', $to->endOfDay());
        $records = $q->limit(50000)->get();
        $filename = 'attendance-history-' . now()->format('Ymd-Hi') . '.xlsx';
        return Excel::download(new AttendanceHistoryExport($records), $filename);
    }
    public function storeManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type'        => ['required', 'in:check_in,check_out'],
            'checked_at'  => ['required', 'date'],
            'work_shift_id'      => ['nullable', 'exists:work_shifts,id'],
            'office_location_id' => ['nullable', 'exists:office_locations,id'],
            'status'      => ['nullable', 'in:normal,late,early_leave,overtime'],
            'late_minutes'=> ['nullable', 'integer', 'min:0'],
            'reason'      => ['required', 'string', 'min:5', 'max:500'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        $checkedAt = Carbon::parse($data['checked_at']);
        $employee  = Employee::findOrFail($data['employee_id']);

        $workDate = $checkedAt->copy()->setTimezone(self::TZ)->toDateString();
        $leave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('is_half_day', false)
            ->whereDate('start_date', '<=', $workDate)
            ->whereDate('end_date', '>=', $workDate)
            ->exists();
        if ($leave) {
            return response()->json(['message' => 'วันนี้มีการลาที่อนุมัติแล้ว ไม่สามารถเพิ่มเวลาเข้า-ออกงานได้'], 422);
        }

        $exists = Attendance::where('employee_id', $employee->id)
            ->where('type', $data['type'])
            ->whereBetween('checked_at', [$checkedAt->copy()->subMinute(), $checkedAt->copy()->addMinute()])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'มีบันทึกเวลาในช่วงเวลาเดียวกันอยู่แล้ว'], 422);
        }

        $shiftId = $data['work_shift_id'] ?? null;
        $shift = $shiftId ? WorkShift::find($shiftId) : $this->resolveShift($employee, $checkedAt);
        $status = $data['status'] ?? 'normal';
        $lateMinutes = $data['late_minutes'] ?? null;

        if (! isset($data['status']) && $shift) {
            $local = $checkedAt->copy()->setTimezone(self::TZ);
            if (! $this->schedule->isHoliday($employee, $local)) {
                if ($data['type'] === 'check_in') {
                    $shiftStart = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->start_time, self::TZ);
                    $diff = $local->diffInMinutes($shiftStart, false);
                    if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                        $status = 'late';
                        $lateMinutes = abs($diff);
                    }
                } elseif ($data['type'] === 'check_out') {
                    $shiftEnd = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->end_time, self::TZ);
                    if ($local->lt($shiftEnd)) $status = 'early_leave';
                    elseif ($local->gt($shiftEnd->copy()->addMinutes(15)) && $this->schedule->allowsOt($employee)) $status = 'overtime';
                }
            }
        }

        $attendance = Attendance::create([
            'employee_id'        => $employee->id,
            'type'               => $data['type'],
            'checked_at'         => $checkedAt,
            'office_location_id' => $data['office_location_id'] ?? null,
            'work_shift_id'      => $shift?->id,
            'status'             => $status,
            'late_minutes'       => $lateMinutes,
            'note'               => $data['note'] ?? null,
            'source'             => 'manual',
            'is_edited'          => true,
            'edited_by'          => Auth::id(),
            'edited_at'          => now(),
            'edit_reason'        => $data['reason'],
        ]);

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'employee_id'   => $employee->id,
            'action'        => 'create',
            'old_values'    => null,
            'new_values'    => $attendance->only([
                'type', 'checked_at', 'status', 'late_minutes',
                'work_shift_id', 'office_location_id', 'note',
            ]),
            'reason'        => $data['reason'],
            'user_id'       => Auth::id(),
        ]);

        return response()->json([
            'data' => $attendance->load(['employee:id,employee_code,first_name,last_name', 'workShift', 'officeLocation', 'editor:id,name']),
            'message' => 'เพิ่มเวลาย้อนหลังเรียบร้อย',
        ], 201);
    }

    /**
     * HR/Admin: เพิ่มเวลาย้อนหลังของพนักงาน "คนเดียว" ทีเดียวหลายวัน
     * รับ employee_id + days[] (แต่ละวันมี date + check_in/check_out เวลา HH:MM ได้อย่างใดอย่างหนึ่งหรือทั้งคู่)
     */
    public function storeManualBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'         => ['required', 'exists:employees,id'],
            'reason'              => ['required', 'string', 'min:5', 'max:500'],
            'work_shift_id'       => ['nullable', 'exists:work_shifts,id'],
            'office_location_id'  => ['nullable', 'exists:office_locations,id'],
            'days'                => ['required', 'array', 'min:1', 'max:62'],
            'days.*.date'         => ['required', 'date'],
            'days.*.check_in'     => ['nullable', 'date_format:H:i'],
            'days.*.check_out'    => ['nullable', 'date_format:H:i'],
            'days.*.note'         => ['nullable', 'string', 'max:500'],
            'days.*.is_ot'        => ['boolean'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $shiftId  = $data['work_shift_id'] ?? null;

        // วันที่ลาแบบเต็มวัน (อนุมัติแล้ว) ในช่วงที่ส่งมา — ห้ามเพิ่มเวลาเข้า-ออกงานทับ
        $dates = collect($data['days'])->pluck('date');
        $fullDayLeaveDates = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('is_half_day', false)
            ->where('start_date', '<=', $dates->max())
            ->where('end_date', '>=', $dates->min())
            ->get(['start_date', 'end_date'])
            ->flatMap(fn ($l) => \Carbon\CarbonPeriod::create($l->start_date, $l->end_date)->toArray())
            ->map(fn ($d) => $d->toDateString())
            ->flip();

        $createdIds = [];
        $updatedIds = [];
        $skipped = [];

        foreach ($data['days'] as $day) {
            if ($fullDayLeaveDates->has($day['date'])) {
                $skipped[] = ['date' => $day['date'], 'type' => 'check_in/check_out', 'reason' => 'วันนี้มีการลาที่อนุมัติแล้ว ไม่สามารถเพิ่มเวลาเข้า-ออกงานได้'];
                continue;
            }
            foreach (['check_in', 'check_out'] as $type) {
                if (empty($day[$type])) {
                    continue;
                }

                $checkedAt   = Carbon::parse($day['date'] . ' ' . $day[$type], self::TZ)->utc();
                $dayStartUtc = Carbon::parse($day['date'], self::TZ)->startOfDay()->utc();
                $dayEndUtc   = Carbon::parse($day['date'], self::TZ)->endOfDay()->utc();

                // หา record ของ type เดียวกันในวัน (Bangkok) เดียวกัน — ถ้ามีให้ "แก้ไข" แทนการสร้างซ้ำ
                $existingSameDay = Attendance::where('employee_id', $employee->id)
                    ->where('type', $type)
                    ->whereBetween('checked_at', [$dayStartUtc, $dayEndUtc])
                    ->orderBy('id')
                    ->get();

                $existing = $existingSameDay->first();

                if ($existing && abs($existing->checked_at->getTimestamp() - $checkedAt->getTimestamp()) <= 60) {
                    $skipped[] = ['date' => $day['date'], 'type' => $type, 'reason' => 'มีบันทึกเวลาในช่วงเวลาเดียวกันอยู่แล้ว'];
                    continue;
                }

                $shift = $shiftId ? WorkShift::find($shiftId) : $this->resolveShift($employee, $checkedAt);
                $status = 'normal';
                $lateMinutes = null;
                $local = $checkedAt->copy()->setTimezone(self::TZ);

                if ($shift && ! $this->schedule->isHoliday($employee, $local)) {
                    if ($type === 'check_in') {
                        $shiftStart = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->start_time, self::TZ);
                        $diff = $local->diffInMinutes($shiftStart, false);
                        if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                            $status = 'late';
                            $lateMinutes = abs($diff);
                        }
                    } else {
                        $shiftEnd = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->end_time, self::TZ);
                        if ($local->lt($shiftEnd)) {
                            $status = 'early_leave';
                        } elseif ($local->gt($shiftEnd->copy()->addMinutes(15)) && $this->schedule->allowsOt($employee)) {
                            $status = 'overtime';
                        }
                    }
                }

                // ระบุด้วยมือว่าเป็นวัน OT (ไม่ต้องพึ่งการคำนวณจากเวลาออกงานเทียบกะ) — เว้นแผนกที่ปิด OT ไว้ ห้ามบังคับแม้ด้วยมือ
                if ($type === 'check_out' && ! empty($day['is_ot']) && $this->schedule->allowsOt($employee)) {
                    $status = 'overtime';
                }

                if ($existing) {
                    // แก้ไข record เดิม + ลบตัวซ้ำที่อาจเกิดจากการกดบันทึกซ้ำในอดีต
                    $oldValues = $existing->only([
                        'type', 'checked_at', 'status', 'late_minutes',
                        'work_shift_id', 'office_location_id', 'note',
                    ]);

                    $existing->fill([
                        'checked_at'         => $checkedAt,
                        'office_location_id' => $data['office_location_id'] ?? $existing->office_location_id,
                        'work_shift_id'      => $shift?->id ?? $existing->work_shift_id,
                        'status'             => $status,
                        'late_minutes'       => $lateMinutes,
                        'note'               => array_key_exists('note', $day) ? $day['note'] : $existing->note,
                        'source'             => 'manual',
                        'is_edited'          => true,
                        'edited_by'          => Auth::id(),
                        'edited_at'          => now(),
                        'edit_reason'        => $data['reason'],
                    ]);
                    $existing->save();

                    AttendanceAuditLog::create([
                        'attendance_id' => $existing->id,
                        'employee_id'   => $employee->id,
                        'action'        => 'update',
                        'old_values'    => $oldValues,
                        'new_values'    => $existing->only([
                            'type', 'checked_at', 'status', 'late_minutes',
                            'work_shift_id', 'office_location_id', 'note',
                        ]),
                        'reason'        => $data['reason'],
                        'user_id'       => Auth::id(),
                    ]);

                    // ลบ duplicate อื่นๆ ของ day/type เดียวกัน (ถ้ามี) — สร้างจากบั๊กเดิมของฟีเจอร์นี้
                    foreach ($existingSameDay->slice(1) as $dup) {
                        AttendanceAuditLog::create([
                            'attendance_id' => $dup->id,
                            'employee_id'   => $employee->id,
                            'action'        => 'delete',
                            'old_values'    => $dup->only([
                                'type', 'checked_at', 'status', 'late_minutes',
                                'work_shift_id', 'office_location_id', 'note',
                            ]),
                            'new_values'    => null,
                            'reason'        => $data['reason'] . ' (ลบรายการซ้ำของวันเดียวกัน)',
                            'user_id'       => Auth::id(),
                        ]);
                        $dup->delete();
                    }

                    $updatedIds[] = $existing->id;
                } else {
                    $attendance = Attendance::create([
                        'employee_id'        => $employee->id,
                        'type'               => $type,
                        'checked_at'         => $checkedAt,
                        'office_location_id' => $data['office_location_id'] ?? null,
                        'work_shift_id'      => $shift?->id,
                        'status'             => $status,
                        'late_minutes'       => $lateMinutes,
                        'note'               => $day['note'] ?? null,
                        'source'             => 'manual',
                        'is_edited'          => true,
                        'edited_by'          => Auth::id(),
                        'edited_at'          => now(),
                        'edit_reason'        => $data['reason'],
                    ]);

                    AttendanceAuditLog::create([
                        'attendance_id' => $attendance->id,
                        'employee_id'   => $employee->id,
                        'action'        => 'create',
                        'old_values'    => null,
                        'new_values'    => $attendance->only([
                            'type', 'checked_at', 'status', 'late_minutes',
                            'work_shift_id', 'office_location_id', 'note',
                        ]),
                        'reason'        => $data['reason'],
                        'user_id'       => Auth::id(),
                    ]);

                    $createdIds[] = $attendance->id;
                }
            }
        }

        return response()->json([
            'message' => 'เพิ่มเวลาย้อนหลังหลายวันเรียบร้อย',
            'summary' => [
                'created' => count($createdIds),
                'updated' => count($updatedIds),
                'skipped' => count($skipped),
                'days'    => count($data['days']),
            ],
            'skipped_detail' => $skipped,
        ], 201);
    }

    /**
     * HR/Admin: แก้ไข attendance ที่มีอยู่ + บันทึก audit log
     */
    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $data = $request->validate([
            'type'         => ['sometimes', 'in:check_in,check_out'],
            'checked_at'   => ['sometimes', 'date'],
            'status'       => ['sometimes', 'in:normal,late,early_leave,overtime'],
            'late_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'work_shift_id'      => ['sometimes', 'nullable', 'exists:work_shifts,id'],
            'office_location_id' => ['sometimes', 'nullable', 'exists:office_locations,id'],
            'note'         => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason'       => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $oldValues = $attendance->only([
            'type', 'checked_at', 'status', 'late_minutes',
            'work_shift_id', 'office_location_id', 'note',
        ]);

        $attendance->fill(collect($data)->except('reason')->toArray());
        $attendance->is_edited = true;
        $attendance->edited_by = Auth::id();
        $attendance->edited_at = now();
        $attendance->edit_reason = $data['reason'];
        $attendance->save();

        $newValues = $attendance->only([
            'type', 'checked_at', 'status', 'late_minutes',
            'work_shift_id', 'office_location_id', 'note',
        ]);

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'employee_id'   => $attendance->employee_id,
            'action'        => 'update',
            'old_values'    => $oldValues,
            'new_values'    => $newValues,
            'reason'        => $data['reason'],
            'user_id'       => Auth::id(),
        ]);

        return response()->json([
            'data' => $attendance->fresh(['employee:id,employee_code,first_name,last_name', 'workShift', 'officeLocation', 'editor:id,name']),
            'message' => 'แก้ไขเวลาเรียบร้อย',
        ]);
    }

    /**
     * HR/Admin: ลบ attendance + บันทึก log
     */
    public function destroy(Request $request, Attendance $attendance): JsonResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ])['reason'];

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'employee_id'   => $attendance->employee_id,
            'action'        => 'delete',
            'old_values'    => $attendance->only([
                'type', 'checked_at', 'status', 'late_minutes',
                'work_shift_id', 'office_location_id', 'note',
            ]),
            'new_values'    => null,
            'reason'        => $reason,
            'user_id'       => Auth::id(),
        ]);

        if ($attendance->photo_path && Storage::disk('public')->exists($attendance->photo_path)) {
            Storage::disk('public')->delete($attendance->photo_path);
        }
        $attendance->delete();

        return response()->json(['message' => 'ลบเวลาเรียบร้อย']);
    }

    /**
     * HR/Admin: ดูประวัติการแก้ไข
     */
    public function auditLogs(Attendance $attendance): JsonResponse
    {
        $logs = AttendanceAuditLog::with('user:id,name')
            ->where('attendance_id', $attendance->id)
            ->orderByDesc('id')
            ->get();
        return response()->json(['data' => $logs]);
    }

    private function resolveEmployee(Request $request): ?Employee
    {
        $user = Auth::user();
        if (! $user) return null;
        return Employee::where('user_id', $user->id)->first();
    }

    private function resolveShift(Employee $employee, Carbon $when): ?WorkShift
    {
        return $this->schedule->resolveShift($employee, $when);
    }

    private function buildMessage(string $type, string $status, bool $outside): string
    {
        $base = $type === 'check_in' ? 'ลงเวลาเข้างานเรียบร้อย' : 'ลงเวลาเลิกงานเรียบร้อย';
        if ($outside) $base .= ' (อยู่นอกพื้นที่ที่กำหนด)';
        if ($status === 'late') $base .= ' [สาย]';
        if ($status === 'early_leave') $base .= ' [ออกก่อนเวลา]';
        if ($status === 'overtime') $base .= ' [ทำ OT]';
        return $base;
    }
}

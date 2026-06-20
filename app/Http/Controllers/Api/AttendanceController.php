<?php

namespace App\Http\Controllers\Api;

use App\Exports\AttendanceHistoryExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\OfficeLocation;
use App\Models\WorkShift;
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
        if ($shift && $data['type'] === 'check_in') {
            $shiftStart = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->start_time, self::TZ);
            $diff = $local->diffInMinutes($shiftStart, false); // negative = late
            if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                $status = 'late';
                $lateMinutes = abs($diff);
            }
        } elseif ($shift && $data['type'] === 'check_out') {
            $shiftEnd = Carbon::parse($local->format('Y-m-d') . ' ' . $shift->end_time, self::TZ);
            if ($local->lt($shiftEnd)) $status = 'early_leave';
            elseif ($local->gt($shiftEnd->copy()->addMinutes(15))) $status = 'overtime';
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
        $q = Attendance::with(['employee:id,employee_code,first_name,last_name', 'officeLocation'])
            ->orderBy('checked_at', 'desc');

        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
        if ($type = $request->string('type')->toString()) $q->where('type', $type);
        if ($from = $request->date('from')) $q->where('checked_at', '>=', $from);
        if ($to   = $request->date('to'))   $q->where('checked_at', '<=', $to->endOfDay());

        return response()->json(['data' => $q->paginate($request->integer('per_page', 30))]);
    }

    /**
     * GET /attendance/export — Excel
     */
    public function export(Request $request): BinaryFileResponse
    {
        $q = Attendance::with(['employee:id,employee_code,first_name,last_name', 'officeLocation'])
            ->orderBy('checked_at', 'desc');
        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
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
                elseif ($local->gt($shiftEnd->copy()->addMinutes(15))) $status = 'overtime';
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
        $assignment = EmployeeShift::with('workShift')
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $when->toDateString())
            ->where(function ($q) use ($when) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $when->toDateString());
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if (! $assignment || ! $assignment->workShift) return null;

        // ตรวจ work_days (1=Mon ... 7=Sun)
        $days = $assignment->work_days;
        if (is_array($days) && count($days) > 0) {
            $dow = $when->dayOfWeekIso; // 1..7
            if (! in_array($dow, array_map('intval', $days), true)) return null;
        }

        return $assignment->workShift;
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

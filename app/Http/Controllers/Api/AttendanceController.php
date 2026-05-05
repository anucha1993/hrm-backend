<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\OfficeLocation;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttendanceController extends Controller
{
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

        // หากะที่ active ของพนักงาน → คำนวณสาย
        $shift = $this->resolveShift($employee, $now);
        $status = 'normal';
        $lateMinutes = null;
        if ($shift && $data['type'] === 'check_in') {
            $shiftStart = Carbon::parse($now->format('Y-m-d') . ' ' . $shift->start_time);
            $diff = $now->diffInMinutes($shiftStart, false); // negative = late
            if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                $status = 'late';
                $lateMinutes = abs($diff);
            }
        } elseif ($shift && $data['type'] === 'check_out') {
            $shiftEnd = Carbon::parse($now->format('Y-m-d') . ' ' . $shift->end_time);
            if ($now->lt($shiftEnd)) $status = 'early_leave';
            elseif ($now->gt($shiftEnd->copy()->addMinutes(15))) $status = 'overtime';
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

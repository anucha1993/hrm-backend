<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * รับ event การตอกบัตรจาก HIP Time 4.0 (ผลักมาจาก sync agent ฝั่ง on-prem ผ่าน token, ไม่ใช่ user login)
 * ดู /memories/repo/hiptime-integration.md สำหรับ context/สถาปัตยกรรมทั้งหมด
 */
class HipTimeIngestController extends Controller
{
    /** เวลาในไฟล์ HIP_DATA เป็นเวลาประเทศไทย (นาฬิกาเครื่องสแกนตั้งเป็นเวลาท้องถิ่น) */
    private const TZ = 'Asia/Bangkok';

    public function __construct(private readonly WorkScheduleService $schedule)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events'                  => ['required', 'array', 'max:2000'],
            'events.*.id'             => ['required'],
            'events.*.enrollnumber'   => ['required', 'string', 'max:50'],
            'events.*.machineno'      => ['nullable'],
            'events.*.datetimescan'   => ['required', 'string'],
            'events.*.timetype'       => ['nullable'],
        ]);

        $checkinTypes = array_map(fn ($v) => strtoupper(trim((string) $v)), config('services.hiptime.checkin_types', []));
        $checkoutTypes = array_map(fn ($v) => strtoupper(trim((string) $v)), config('services.hiptime.checkout_types', []));

        $employeesByEnroll = Employee::whereNotNull('hip_enroll_number')
            ->select('id', 'hip_enroll_number', 'employment_type_id')
            ->get()
            ->keyBy(fn (Employee $e) => trim((string) $e->hip_enroll_number));

        $pieceId = EmploymentType::where('code', 'PIECEWORK')->value('id');

        $created = 0;
        $skipped = 0;
        $unmapped = [];
        $errors = [];

        foreach ($data['events'] as $event) {
            $sourceRef = 'hiptime:' . $event['id'];

            if (Attendance::where('source_ref', $sourceRef)->exists()) {
                $skipped++;
                continue;
            }

            $enroll = trim((string) $event['enrollnumber']);
            $employee = $employeesByEnroll->get($enroll);
            if (! $employee) {
                $unmapped[$enroll] = true;
                $skipped++;
                continue;
            }

            if ($pieceId !== null && (int) $employee->employment_type_id === (int) $pieceId) {
                $skipped++;
                continue;
            }

            $timetype = isset($event['timetype']) ? strtoupper(trim((string) $event['timetype'])) : null;
            $type = null;
            if ($timetype !== null && in_array($timetype, $checkinTypes, true)) {
                $type = 'check_in';
            } elseif ($timetype !== null && in_array($timetype, $checkoutTypes, true)) {
                $type = 'check_out';
            }
            if ($type === null) {
                $errors[] = ['source_ref' => $sourceRef, 'error' => "timetype '{$timetype}' ไม่ได้ map ไว้ (ปรับได้ที่ HIPTIME_CHECKIN_TYPES/HIPTIME_CHECKOUT_TYPES)"];
                $skipped++;
                continue;
            }

            try {
                $checkedAt = Carbon::parse($event['datetimescan'], self::TZ);
            } catch (\Throwable $e) {
                $errors[] = ['source_ref' => $sourceRef, 'error' => 'datetimescan รูปแบบไม่ถูกต้อง: ' . $event['datetimescan']];
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($employee, $type, $checkedAt, $sourceRef, $event, &$created) {
                    $this->createRecord($employee, $type, $checkedAt, $sourceRef, $event['machineno'] ?? null);
                });
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['source_ref' => $sourceRef, 'error' => $e->getMessage()];
                $skipped++;
            }
        }

        return response()->json([
            'message' => "รับข้อมูล {$created} รายการ" . ($skipped > 0 ? " · ข้าม {$skipped} รายการ" : ''),
            'summary' => [
                'received' => count($data['events']),
                'created'  => $created,
                'skipped'  => $skipped,
                'unmapped_enroll_numbers' => array_keys($unmapped),
            ],
            'errors' => $errors,
        ]);
    }

    private function createRecord(Employee $employee, string $type, Carbon $checkedAt, string $sourceRef, $machineNo): void
    {
        $utc = $checkedAt->copy()->utc();
        $tz = $checkedAt->getTimezone();

        $shift = $this->schedule->resolveShift($employee, $checkedAt);
        $status = 'normal';
        $lateMinutes = null;

        if ($shift && ! $this->schedule->isHoliday($employee, $checkedAt)) {
            if ($type === 'check_in') {
                $shiftStart = Carbon::parse($checkedAt->format('Y-m-d') . ' ' . $shift->start_time, $tz);
                $diff = $checkedAt->diffInMinutes($shiftStart, false);
                if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                    $status = 'late';
                    $lateMinutes = abs($diff);
                }
            } else { // check_out
                $shiftEnd = Carbon::parse($checkedAt->format('Y-m-d') . ' ' . $shift->end_time, $tz);
                if ($checkedAt->lt($shiftEnd)) {
                    $status = 'early_leave';
                } elseif ($checkedAt->gt($shiftEnd->copy()->addMinutes(15))) {
                    $status = 'overtime';
                }
            }
        }

        $attendance = Attendance::create([
            'employee_id'   => $employee->id,
            'type'          => $type,
            'checked_at'    => $utc,
            'work_shift_id' => $shift?->id,
            'status'        => $status,
            'late_minutes'  => $lateMinutes,
            'note'          => $machineNo !== null ? "HIP Time เครื่อง #{$machineNo}" : null,
            'source'        => 'device',
            'source_ref'    => $sourceRef,
        ]);

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'employee_id'   => $employee->id,
            'action'        => 'create',
            'old_values'    => null,
            'new_values'    => $attendance->only(['type', 'checked_at', 'status', 'late_minutes', 'work_shift_id', 'note']),
            'reason'        => 'ซิงค์อัตโนมัติจาก HIP Time',
            'user_id'       => null,
        ]);
    }
}

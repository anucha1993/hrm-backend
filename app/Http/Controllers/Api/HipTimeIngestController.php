<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\HipTimeSyncLog;
use App\Services\HipTimeReclassifyService;
use App\Services\WorkScheduleService;
use App\Support\HipTimeAttendanceWindow;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * รับ event การตอกบัตรจาก HIP Time 4.0 (ผลักมาจาก sync agent ฝั่ง on-prem ผ่าน token, ไม่ใช่ user login)
 * ดู /memories/repo/hiptime-integration.md สำหรับ context/สถาปัตยกรรมทั้งหมด
 *
 * เก็บทุก record ที่ sync มาไว้หมด ไม่กรอง/ไม่รวมที่นี่ — การเลือก record ที่ดีที่สุดต่อวันมาแสดง
 * ทำที่ชั้นแสดงผลแทน (ดู AttendanceController::index + App\Support\HipTimeAttendanceWindow)
 */
class HipTimeIngestController extends Controller
{
    /** เวลาในไฟล์ HIP_DATA เป็นเวลาประเทศไทย (นาฬิกาเครื่องสแกนตั้งเป็นเวลาท้องถิ่น) */
    private const TZ = 'Asia/Bangkok';

    public function __construct(
        private readonly WorkScheduleService $schedule,
        private readonly HipTimeReclassifyService $reclassify,
    )
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

        $employeesByEnroll = Employee::whereNotNull('hip_enroll_number')
            ->select('id', 'hip_enroll_number', 'employment_type_id')
            ->get()
            ->keyBy(fn (Employee $e) => trim((string) $e->hip_enroll_number));

        $pieceId = EmploymentType::where('code', 'PIECEWORK')->value('id');

        $created = 0;
        $skipped = 0;
        $unmapped = [];
        // เก็บ id เฉพาะที่ข้ามเพราะ enrollnumber ยัง map ไม่เจอ เพื่อให้ agent รู้ว่าต้อง sync ซ้ำ id นี้ในรอบถัดไป (ไม่ข้ามถาวร)
        $unmappedIds = [];
        $errors = [];
        // เก็บคู่ (employee_id, workDate) ที่มี record ใหม่เข้ามา เพื่อจัดประเภทเข้า/ออกงานใหม่ทั้งวันหลัง sync เสร็จ
        $touchedGroups = [];

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
                $unmappedIds[] = $event['id'];
                $skipped++;
                continue;
            }

            if ($pieceId !== null && (int) $employee->employment_type_id === (int) $pieceId) {
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

            // จัดประเภทเข้างาน/ออกงานจากเวลาที่สแกนจริง (เครื่องส่ง timetype 'IN' เสมอ ไม่ว่าจะสแกนตอนไหน) — เดาไว้ก่อน
            // แล้วจัดใหม่ทั้งวันอีกทีด้านล่างหลัง insert ครบ (กันเคสมาสายเกินช่วงเข้างาน ดู HipTimeReclassifyService)
            [$type] = HipTimeAttendanceWindow::classify($checkedAt);

            try {
                DB::transaction(function () use ($employee, $type, $checkedAt, $sourceRef, $event, &$created) {
                    $this->createRecord($employee, $type, $checkedAt, $sourceRef, $event['machineno'] ?? null);
                });
                $created++;
                $touchedGroups[$employee->id . '|' . $this->reclassify->bucketDate($checkedAt)] = true;
            } catch (\Throwable $e) {
                $errors[] = ['source_ref' => $sourceRef, 'error' => $e->getMessage()];
                $skipped++;
            }
        }

        foreach (array_keys($touchedGroups) as $group) {
            [$employeeId, $workDate] = explode('|', $group, 2);
            try {
                $this->reclassify->reclassifyGroup((int) $employeeId, $workDate);
            } catch (\Throwable $e) {
                $errors[] = ['employee_id' => $employeeId, 'work_date' => $workDate, 'error' => 'reclassify: ' . $e->getMessage()];
            }
        }

        $message = "รับข้อมูล {$created} รายการ" . ($skipped > 0 ? " · ข้าม {$skipped} รายการ" : '');

        HipTimeSyncLog::create([
            'received' => count($data['events']),
            'created' => $created,
            'skipped' => $skipped,
            // array key ตัวเลขล้วน (เช่น "5168") ถูก PHP แปลงเป็น int อัตโนมัติ ต้อง cast กลับเป็น string
            'unmapped_enroll_numbers' => array_map('strval', array_keys($unmapped)),
            'unmapped_ids' => array_values($unmappedIds),
            'errors' => $errors,
            'message' => $message,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => $message,
            'summary' => [
                'received' => count($data['events']),
                'created'  => $created,
                'skipped'  => $skipped,
                'unmapped_enroll_numbers' => array_map('strval', array_keys($unmapped)),
                'unmapped_ids' => array_values($unmappedIds),
            ],
            'errors' => $errors,
        ]);
    }

    /** ดูประวัติการ sync จาก agent (แสดงในหน้าตั้งค่า HIP Time) */
    public function syncLogs(Request $request): JsonResponse
    {
        $logs = HipTimeSyncLog::query()
            ->orderByDesc('id')
            ->limit($request->integer('limit', 100))
            ->get();

        return response()->json(['data' => $logs]);
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


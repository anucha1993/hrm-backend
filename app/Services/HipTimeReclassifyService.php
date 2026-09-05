<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Employee;
use App\Support\HipTimeAttendanceWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * จัดกลุ่ม scan ของ HIP Time (source=device) เป็นราย "วันทำงาน" ต่อพนักงาน แล้วตัดสินว่าอันไหนเข้างาน/ออกงาน
 * ใช้ร่วมกันโดย HipTimeIngestController (แก้ทันทีหลัง sync แต่ละครั้ง) และคำสั่ง hiptime:reclassify (ไล่แก้ย้อนหลังทั้งหมด)
 * ดู /memories/repo/hiptime-integration.md สำหรับ context
 */
class HipTimeReclassifyService
{
    private const TZ = 'Asia/Bangkok';

    public function __construct(private readonly WorkScheduleService $schedule)
    {
    }

    /** วันที่ที่ scan นี้เข้ากลุ่มด้วย (สแกนช่วงดึกก่อนเข้าช่วงเข้างานนับเป็นของเมื่อวาน กันเคสออกงานข้ามเที่ยงคืน) */
    public function bucketDate(Carbon $local): string
    {
        return HipTimeAttendanceWindow::workDateFor('check_out', $local);
    }

    /**
     * แก้ไข type/status/late_minutes ของ scan ทั้งหมดในวันทำงานเดียวของพนักงานคนหนึ่ง ให้ตรงกับรูปแบบการสแกนทั้งวัน
     * @return array<int, array{id:int, employee:string, from:string, to:string}> รายการที่มีการเปลี่ยนแปลง (หรือจะเปลี่ยนถ้า dry-run)
     */
    public function reclassifyGroup(int $employeeId, string $workDate, bool $dryRun = false): array
    {
        $employee = Employee::find($employeeId);
        if (! $employee) {
            return [];
        }

        $rangeStart = Carbon::parse($workDate, self::TZ)->subDay()->startOfDay()->utc();
        $rangeEnd = Carbon::parse($workDate, self::TZ)->addDay()->endOfDay()->utc();

        $rows = Attendance::where('employee_id', $employeeId)
            ->where('source', 'device')
            ->whereBetween('checked_at', [$rangeStart, $rangeEnd])
            ->orderBy('checked_at')
            ->get()
            ->filter(fn (Attendance $a) => $this->bucketDate($a->checked_at->copy()->setTimezone(self::TZ)) === $workDate)
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $locals = $rows->map(fn (Attendance $a) => $a->checked_at->copy()->setTimezone(self::TZ))->all();
        $indices = HipTimeAttendanceWindow::classifyGroup($locals);

        $diffs = [];

        foreach ($rows as $i => $attendance) {
            $type = $i === $indices['check_in'] ? 'check_in' : 'check_out';
            $local = $locals[$i];

            $shift = $this->schedule->resolveShift($employee, $local);
            $isHoliday = $shift ? $this->schedule->isHoliday($employee, $local) : false;
            [$status, $lateMinutes] = $this->computeStatus($type, $local, $shift, $isHoliday, $this->schedule->allowsOt($employee));

            $needsUpdate = $attendance->type !== $type
                || $attendance->status !== $status
                || (int) ($attendance->late_minutes ?? 0) !== (int) ($lateMinutes ?? 0)
                || (int) ($attendance->work_shift_id ?? 0) !== (int) ($shift?->id ?? 0);

            if (! $needsUpdate) {
                continue;
            }

            $diffs[] = [
                'id' => $attendance->id,
                'employee' => $employee->employee_code ?? (string) $employeeId,
                'from' => sprintf('%s (%s, %s)', $attendance->type, $attendance->status, $attendance->late_minutes ?? '-'),
                'to' => sprintf('%s (%s, %s)', $type, $status, $lateMinutes ?? '-'),
            ];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($attendance, $type, $status, $lateMinutes, $shift) {
                $old = $attendance->only(['type', 'checked_at', 'status', 'late_minutes', 'work_shift_id']);

                $attendance->type = $type;
                $attendance->status = $status;
                $attendance->late_minutes = $lateMinutes;
                $attendance->work_shift_id = $shift?->id;
                $attendance->save();

                AttendanceAuditLog::create([
                    'attendance_id' => $attendance->id,
                    'employee_id'   => $attendance->employee_id,
                    'action'        => 'update',
                    'old_values'    => $old,
                    'new_values'    => $attendance->only(['type', 'checked_at', 'status', 'late_minutes', 'work_shift_id']),
                    'reason'        => 'ปรับประเภทเข้า/ออกงานตามรูปแบบการสแกนทั้งวัน HIP Time',
                    'user_id'       => null,
                ]);
            });
        }

        return $diffs;
    }

    /** @return array{0:string,1:?int} [status, lateMinutes] */
    private function computeStatus(string $type, Carbon $checkedAt, $shift, bool $isHoliday, bool $allowsOt = true): array
    {
        $status = 'normal';
        $lateMinutes = null;

        if ($shift && ! $isHoliday) {
            $tz = $checkedAt->getTimezone();
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
                } elseif ($checkedAt->gt($shiftEnd->copy()->addMinutes(15)) && $allowsOt) {
                    $status = 'overtime';
                }
            }
        }

        return [$status, $lateMinutes];
    }
}

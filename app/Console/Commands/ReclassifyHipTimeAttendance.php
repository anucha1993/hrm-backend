<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Services\WorkScheduleService;
use App\Support\HipTimeAttendanceWindow;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * แก้ไขย้อนหลัง: record ของ HIP Time (source=device) ที่ถูกสร้างไว้ก่อนจะเปลี่ยนมาจัดประเภทเข้า/ออกงาน
 * ตามช่วงเวลาสแกน (HipTimeAttendanceWindow) ยังคงค่า type/status เดิมที่เชื่อ timetype ดิบจากเครื่อง (เป็น 'IN' เสมอ)
 * คำสั่งนี้ไล่ตรวจทุก record แล้วปรับ type/status/late_minutes ใหม่ให้ตรงกับกติกาปัจจุบัน
 */
class ReclassifyHipTimeAttendance extends Command
{
    protected $signature = 'hiptime:reclassify {--dry-run : แสดงรายการที่จะแก้ไขโดยไม่บันทึก}';

    protected $description = 'ปรับ type/status ของ Attendance ที่มาจาก HIP Time ย้อนหลัง ให้ตรงกับกติกาช่วงเวลาสแกนปัจจุบัน';

    private const TZ = 'Asia/Bangkok';

    public function __construct(private readonly WorkScheduleService $schedule)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = Attendance::with('employee')->where('source', 'device')->orderBy('checked_at')->get();

        $changed = 0;

        foreach ($rows as $attendance) {
            $local = $attendance->checked_at->copy()->setTimezone(self::TZ);
            [$correctType] = HipTimeAttendanceWindow::classify($local);

            $employee = $attendance->employee;
            $shift = $employee ? $this->schedule->resolveShift($employee, $local) : null;
            $isHoliday = $employee && $shift ? $this->schedule->isHoliday($employee, $local) : false;

            [$status, $lateMinutes] = $this->computeStatus($correctType, $local, $shift, $isHoliday);

            $needsUpdate = $attendance->type !== $correctType
                || $attendance->status !== $status
                || (int) ($attendance->late_minutes ?? 0) !== (int) ($lateMinutes ?? 0)
                || (int) ($attendance->work_shift_id ?? 0) !== (int) ($shift?->id ?? 0);

            if (! $needsUpdate) {
                continue;
            }

            $changed++;
            $this->line(sprintf(
                '#%d %s: %s (%s, %s) -> %s (%s, %s)',
                $attendance->id,
                $employee?->name ?? $attendance->employee_id,
                $attendance->type,
                $attendance->status,
                $attendance->late_minutes ?? '-',
                $correctType,
                $status,
                $lateMinutes ?? '-'
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($attendance, $correctType, $status, $lateMinutes, $shift) {
                $old = $attendance->only(['type', 'checked_at', 'status', 'late_minutes', 'work_shift_id']);

                $attendance->type = $correctType;
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
                    'reason'        => 'ปรับประเภทเข้า/ออกงานตามช่วงเวลาสแกน HIP Time (แก้ไขย้อนหลัง)',
                    'user_id'       => null,
                ]);
            });
        }

        $this->info($dryRun
            ? "พบ {$changed} รายการที่ต้องแก้ไข (dry-run, ไม่ได้บันทึก)"
            : "แก้ไขแล้ว {$changed} รายการ จากทั้งหมด {$rows->count()} รายการ");

        return self::SUCCESS;
    }

    /** @return array{0:string,1:?int} [status, lateMinutes] */
    private function computeStatus(string $type, Carbon $checkedAt, $shift, bool $isHoliday): array
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
                } elseif ($checkedAt->gt($shiftEnd->copy()->addMinutes(15))) {
                    $status = 'overtime';
                }
            }
        }

        return [$status, $lateMinutes];
    }
}

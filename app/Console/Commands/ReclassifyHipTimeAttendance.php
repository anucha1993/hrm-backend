<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\HipTimeReclassifyService;
use Illuminate\Console\Command;

/**
 * แก้ไขย้อนหลัง: จัดกลุ่ม scan ของ HIP Time (source=device) เป็นราย "วันทำงาน" ต่อพนักงาน
 * แล้วปรับ type/status/late_minutes ใหม่ให้ตรงกับรูปแบบการสแกนทั้งวัน (ดู HipTimeReclassifyService)
 */
class ReclassifyHipTimeAttendance extends Command
{
    protected $signature = 'hiptime:reclassify {--dry-run : แสดงรายการที่จะแก้ไขโดยไม่บันทึก}';

    protected $description = 'ปรับ type/status ของ Attendance ที่มาจาก HIP Time ย้อนหลัง ให้ตรงกับรูปแบบการสแกนทั้งวัน';

    private const TZ = 'Asia/Bangkok';

    public function __construct(private readonly HipTimeReclassifyService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // เก็บคู่ (employee_id, workDate) ที่ไม่ซ้ำ จาก scan device ทั้งหมด
        $pairs = Attendance::where('source', 'device')
            ->orderBy('checked_at')
            ->get(['employee_id', 'checked_at'])
            ->map(function (Attendance $a) {
                $local = $a->checked_at->copy()->setTimezone(self::TZ);
                return $a->employee_id . '|' . $this->service->bucketDate($local);
            })
            ->unique()
            ->values();

        $changed = 0;

        foreach ($pairs as $pair) {
            [$employeeId, $workDate] = explode('|', $pair, 2);
            $diffs = $this->service->reclassifyGroup((int) $employeeId, $workDate, $dryRun);
            foreach ($diffs as $d) {
                $changed++;
                $this->line(sprintf('#%d %s (%s): %s -> %s', $d['id'], $d['employee'], $workDate, $d['from'], $d['to']));
            }
        }

        $this->info($dryRun
            ? "พบ {$changed} รายการที่ต้องแก้ไข (dry-run, ไม่ได้บันทึก) จาก {$pairs->count()} วันทำงาน"
            : "แก้ไขแล้ว {$changed} รายการ จาก {$pairs->count()} วันทำงาน");

        return self::SUCCESS;
    }
}

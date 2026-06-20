<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\EmploymentType;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ImportTimesheet extends Command
{
    protected $signature = 'attendance:import-timesheet
        {file : path ไปยังไฟล์ .xlsx (เช่น d:/Programing/CYC-HRM/time.xlsx)}
        {--sheet=0 : ลำดับชีตที่มีข้อมูล (เริ่มจาก 0)}
        {--update-types : ปรับ "ประเภทการจ้าง" ของพนักงานตามไฟล์ด้วย}
        {--dry-run : แสดงผลโดยไม่บันทึกลงฐานข้อมูล}';

    protected $description = 'นำเข้าบันทึกเวลาเข้า-ออกจากไฟล์ timesheet (รูปแบบ ชม./นาที แยกคอลัมน์) และปรับประเภทการจ้างตามไฟล์';

    /** timezone ของธุรกิจ (เวลาในไฟล์เป็นเวลาประเทศไทย) — เก็บลง DB เป็น UTC */
    private const TZ = 'Asia/Bangkok';

    /** ชื่อหัวคอลัมน์ที่คาดหวัง → key ภายใน */
    private const HEADER_MAP = [
        'วันทำงาน'              => 'date',
        'รหัสพนักงาน'           => 'code',
        'ประเภทพนักงาน'         => 'type',
        'เวลาเข้างาน_ชม.'        => 'in_h',
        'เวลาเข้างาน_นาที'       => 'in_m',
        'เวลาออกงาน_ชม.'        => 'out_h',
        'เวลาออกงาน_นาที'       => 'out_m',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์: {$path}");
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $updateTypes = (bool) $this->option('update-types');
        $sheetIdx = (int) $this->option('sheet');

        $sheets = Excel::toArray(null, new \Illuminate\Http\UploadedFile($path, basename($path), null, null, true));
        $rows = $sheets[$sheetIdx] ?? null;
        if (! $rows || count($rows) < 2) {
            $this->error('ไฟล์ว่างเปล่า หรือไม่มีข้อมูลในชีตที่เลือก');
            return self::FAILURE;
        }

        // หา index ของแต่ละคอลัมน์จากชื่อหัวตาราง
        $cols = $this->resolveColumns($rows[0]);
        foreach (['date', 'code', 'in_h', 'in_m', 'out_h', 'out_m'] as $required) {
            if (! isset($cols[$required])) {
                $this->error("ไม่พบคอลัมน์ที่จำเป็น: {$required}");
                return self::FAILURE;
            }
        }
        $data = array_slice($rows, 1);

        // === เตรียม lookup ===
        $employees = Employee::select('id', 'employee_code', 'employment_type_id')
            ->get()
            ->keyBy(fn ($e) => mb_strtoupper(trim((string) $e->employee_code)));

        $typeByName = EmploymentType::pluck('id', 'name'); // name => id

        // === Pass 1: ประเภทการจ้าง (code => typeName) ===
        $codeType = [];
        foreach ($data as $r) {
            $code = $this->cell($r, $cols, 'code');
            $type = $this->cell($r, $cols, 'type');
            if ($code === null || $type === null) continue;
            $codeType[mb_strtoupper($code)] = $type;
        }

        $missingCodes = [];
        $typeUpdated = 0;
        $typeUnknown = [];
        $pieceId = EmploymentType::where('code', 'PIECEWORK')->value('id');

        // === ประมวลผล ===
        $created = 0;
        $skippedNoTime = 0;
        $skippedDup = 0;
        $skippedNoEmp = 0;
        $skippedPiece = 0;
        $pieceEmps = [];
        $rowsWithMissingEmp = [];

        $run = function () use (
            $data, $cols, $employees, $codeType, $typeByName, $updateTypes, $dry, $pieceId,
            &$created, &$skippedNoTime, &$skippedDup, &$skippedNoEmp,
            &$missingCodes, &$typeUpdated, &$typeUnknown, &$rowsWithMissingEmp,
            &$skippedPiece, &$pieceEmps
        ) {
            // ปรับประเภทการจ้าง
            if ($updateTypes) {
                foreach ($codeType as $code => $typeName) {
                    $emp = $employees[$code] ?? null;
                    if (! $emp) { $missingCodes[$code] = true; continue; }
                    $typeId = $typeByName[$typeName] ?? null;
                    if (! $typeId) { $typeUnknown[$typeName] = true; continue; }
                    if ((int) $emp->employment_type_id !== (int) $typeId) {
                        if (! $dry) {
                            Employee::whereKey($emp->id)->update(['employment_type_id' => $typeId]);
                        }
                        $typeUpdated++;
                    }
                }
            }

            // นำเข้าบันทึกเวลา
            foreach ($data as $r) {
                $code = $this->cell($r, $cols, 'code');
                if ($code === null) continue;
                $codeU = mb_strtoupper($code);

                $dateStr = $this->parseSerialDate($r[$cols['date']] ?? null);
                if ($dateStr === null) continue;

                $in = $this->parseHm($r[$cols['in_h']] ?? null, $r[$cols['in_m']] ?? null);
                $out = $this->parseHm($r[$cols['out_h']] ?? null, $r[$cols['out_m']] ?? null);
                if ($in === null && $out === null) { continue; } // ไม่มีเวลา = ขาด/วันหยุด ข้าม

                $emp = $employees[$codeU] ?? null;
                if (! $emp) {
                    $missingCodes[$codeU] = true;
                    $rowsWithMissingEmp[$codeU] = ($rowsWithMissingEmp[$codeU] ?? 0) + 1;
                    $skippedNoEmp += ($in !== null ? 1 : 0) + ($out !== null ? 1 : 0);
                    continue;
                }

                // ข้ามพนักงาน "จ้างตามชิ้นงาน" (งานเหมา) — ไม่เก็บเวลา คิดค่าจ้างตามการผลิต ไม่เกี่ยวกฎการหัก
                $fileTypeId = $typeByName[$codeType[$codeU] ?? ''] ?? null;
                if ($pieceId !== null && ($fileTypeId === $pieceId || (int) $emp->employment_type_id === (int) $pieceId)) {
                    $pieceEmps[$codeU] = true;
                    $skippedPiece += ($in !== null ? 1 : 0) + ($out !== null ? 1 : 0);
                    continue;
                }

                if ($in !== null) {
                    $res = $this->createRecord($emp->id, 'check_in', Carbon::parse("$dateStr $in", self::TZ), $dry);
                    $res ? $created++ : $skippedDup++;
                }
                if ($out !== null) {
                    $res = $this->createRecord($emp->id, 'check_out', Carbon::parse("$dateStr $out", self::TZ), $dry);
                    $res ? $created++ : $skippedDup++;
                }
            }
        };

        if ($dry) {
            $run();
        } else {
            DB::transaction($run);
        }

        // === รายงานผล ===
        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '') . 'สรุปผลการนำเข้า');
        $this->line("  • เวลาเข้า-ออกที่สร้าง: <fg=green>{$created}</> records");
        $this->line("  • ข้ามเพราะซ้ำ: {$skippedDup}");
        $this->line("  • ข้ามเพราะไม่พบพนักงาน: {$skippedNoEmp} records");
        $this->line("  • ข้ามพนักงานจ้างตามชิ้นงาน (งานเหมา): <fg=yellow>{$skippedPiece}</> records / " . count($pieceEmps) . " คน");
        if ($updateTypes) {
            $this->line("  • ปรับประเภทการจ้าง: <fg=green>{$typeUpdated}</> คน");
            if ($typeUnknown) {
                $this->warn('    ประเภทที่ไม่รู้จัก (ยังไม่มีใน DB): ' . implode(', ', array_keys($typeUnknown)));
            }
        }
        if ($missingCodes) {
            $this->newLine();
            $this->warn('รหัสพนักงานที่ไม่พบใน DB (' . count($missingCodes) . '): ' . implode(', ', array_keys($missingCodes)));
        }

        return self::SUCCESS;
    }

    /** หา index คอลัมน์จากชื่อหัวตาราง (fallback: ตำแหน่งคงที่) */
    private function resolveColumns(array $header): array
    {
        $cols = [];
        foreach ($header as $idx => $name) {
            $name = is_string($name) ? trim($name) : $name;
            if (isset(self::HEADER_MAP[$name])) {
                $cols[self::HEADER_MAP[$name]] = $idx;
            }
        }
        // fallback ตามตำแหน่งคอลัมน์เดิม ถ้าหัวตารางไม่ตรง
        $fallback = ['date' => 0, 'code' => 1, 'type' => 3, 'in_h' => 9, 'in_m' => 10, 'out_h' => 12, 'out_m' => 13];
        foreach ($fallback as $k => $i) {
            if (! isset($cols[$k])) $cols[$k] = $i;
        }
        return $cols;
    }

    private function cell(array $row, array $cols, string $key): ?string
    {
        if (! isset($cols[$key])) return null;
        $v = $row[$cols[$key]] ?? null;
        if (is_string($v)) $v = trim($v);
        return ($v === '' || $v === null) ? null : (string) $v;
    }

    /** แปลง Excel serial / สตริงวันที่ → 'Y-m-d' */
    private function parseSerialDate($value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            if (is_numeric($value)) {
                $c = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value));
            } else {
                $c = Carbon::parse($value);
            }
        } catch (\Throwable $e) {
            return null;
        }
        if ($c->year >= 2400) $c = $c->copy()->subYears(543);
        return $c->format('Y-m-d');
    }

    /** รวม ชั่วโมง+นาที → 'H:i' (คืน null ถ้าว่างทั้งคู่) */
    private function parseHm($h, $m): ?string
    {
        $hBlank = ($h === null || $h === '');
        $mBlank = ($m === null || $m === '');
        if ($hBlank && $mBlank) return null;
        $hi = is_numeric($h) ? (int) $h : 0;
        $mi = is_numeric($m) ? (int) $m : 0;
        if ($hi < 0 || $hi > 23 || $mi < 0 || $mi > 59) return null;
        return sprintf('%02d:%02d', $hi, $mi);
    }

    /**
     * สร้าง Attendance 1 record + คำนวณสาย/ออกก่อน/OT + audit log
     * คืน false ถ้าซ้ำ (กันซ้ำ ±1 นาที พนักงาน+ประเภทเดียวกัน)
     */
    private function createRecord(int $employeeId, string $type, Carbon $checkedAt, bool $dry): bool
    {
        // $checkedAt เป็นเวลาท้องถิ่น (Asia/Bangkok) — เก็บลง DB เป็น UTC แต่คำนวณสถานะบนเวลาท้องถิ่น
        $utc = $checkedAt->copy()->utc();
        $tz = $checkedAt->getTimezone();

        $exists = Attendance::where('employee_id', $employeeId)
            ->where('type', $type)
            ->whereBetween('checked_at', [$utc->copy()->subMinute(), $utc->copy()->addMinute()])
            ->exists();
        if ($exists) return false;

        $shift = $this->resolveShift($employeeId, $checkedAt);
        $status = 'normal';
        $lateMinutes = null;

        if ($shift) {
            if ($type === 'check_in') {
                $shiftStart = Carbon::parse($checkedAt->format('Y-m-d') . ' ' . $shift->start_time, $tz);
                $diff = $checkedAt->diffInMinutes($shiftStart, false);
                if ($diff < -intval($shift->late_grace_minutes ?? 0)) {
                    $status = 'late';
                    $lateMinutes = abs($diff);
                }
            } else {
                $shiftEnd = Carbon::parse($checkedAt->format('Y-m-d') . ' ' . $shift->end_time, $tz);
                if ($checkedAt->lt($shiftEnd)) {
                    $status = 'early_leave';
                } elseif ($checkedAt->gt($shiftEnd->copy()->addMinutes(15))) {
                    $status = 'overtime';
                }
            }
        }

        if ($dry) return true;

        $attendance = Attendance::create([
            'employee_id' => $employeeId,
            'type' => $type,
            'checked_at' => $utc,
            'work_shift_id' => $shift?->id,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'note' => null,
            'source' => 'manual',
            'is_edited' => true,
            'edited_by' => null,
            'edited_at' => now(),
            'edit_reason' => 'นำเข้าจากไฟล์ timesheet',
        ]);

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employeeId,
            'action' => 'create',
            'old_values' => null,
            'new_values' => $attendance->only(['type', 'checked_at', 'status', 'late_minutes', 'work_shift_id']),
            'reason' => 'นำเข้าจากไฟล์ timesheet',
            'user_id' => null,
        ]);

        return true;
    }

    private function resolveShift(int $employeeId, Carbon $when): ?WorkShift
    {
        $assignment = EmployeeShift::with('workShift')
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $when->toDateString())
            ->where(function ($q) use ($when) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $when->toDateString());
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if (! $assignment || ! $assignment->workShift) return null;

        $days = $assignment->work_days;
        if (is_array($days) && count($days) > 0) {
            if (! in_array($when->dayOfWeekIso, array_map('intval', $days), true)) return null;
        }

        return $assignment->workShift;
    }
}

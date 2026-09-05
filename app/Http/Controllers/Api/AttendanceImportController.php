<?php

namespace App\Http\Controllers\Api;

use App\Exports\AttendanceImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\WorkShift;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceImportController extends Controller
{
    /** timezone ของธุรกิจ (เวลาในไฟล์เป็นเวลาประเทศไทย) — เก็บลง DB เป็น UTC */
    private const TZ = 'Asia/Bangkok';

    public function __construct(private readonly WorkScheduleService $schedule)
    {
    }

    /**
     * ดาวน์โหลดไฟล์ template สำหรับนำเข้าเวลาทำงาน
     */
    public function template()
    {
        $filename = 'attendance_import_template_' . date('Ymd') . '.xlsx';
        return Excel::download(new AttendanceImportTemplateExport(), $filename);
    }

    /**
     * นำเข้าข้อมูลเวลาทำงานจากไฟล์ Excel
     * รูปแบบ 1 แถว = 1 วัน ของพนักงาน 1 คน (มีเวลาเข้า/ออก)
     * แต่ละแถวจะสร้างได้สูงสุด 2 records (check_in + check_out)
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $rows = Excel::toArray(null, $request->file('file'))[0] ?? [];
        if (count($rows) < 2) {
            return response()->json(['message' => 'ไฟล์ว่างเปล่า หรือไม่มีข้อมูล'], 422);
        }

        $headers = array_map(fn ($h) => is_string($h) ? trim($h) : $h, $rows[0]);
        $dataRows = array_slice($rows, 1);

        // map employee_code (พิมพ์ใหญ่) → Employee
        $employees = Employee::select('id', 'employee_code', 'employment_type_id', 'department_id')
            ->get()
            ->keyBy(fn ($e) => mb_strtoupper(trim((string) $e->employee_code)));

        $pieceId = EmploymentType::where('code', 'PIECEWORK')->value('id');
        $noTrackDeptIds = Department::where('attendance_mode', Department::ATTENDANCE_NONE)->pluck('id')->all();

        $userId = Auth::id();
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($dataRows as $i => $row) {
            $rowNum = $i + 2; // +1 header, +1 ดัชนีเริ่ม 0

            // สร้าง assoc array จาก header
            $assoc = [];
            foreach ($headers as $idx => $header) {
                if (! $header) continue;
                $val = $row[$idx] ?? null;
                if (is_string($val)) $val = trim($val);
                $assoc[$header] = ($val === '') ? null : $val;
            }

            // ข้ามแถวว่าง (พิจารณาเฉพาะคอลัมน์ที่ระบบใช้ เพื่อกันข้อความคำแนะนำในคอลัมน์อื่น)
            $hasData = ($assoc['employee_code'] ?? null) !== null
                || ($assoc['date'] ?? null) !== null
                || ($assoc['check_in'] ?? null) !== null
                || ($assoc['check_out'] ?? null) !== null;
            if (! $hasData) {
                continue;
            }

            $code = $assoc['employee_code'] ?? null;
            $rowErrors = [];

            $employee = $code ? ($employees[mb_strtoupper((string) $code)] ?? null) : null;
            if (! $code) {
                $rowErrors[] = 'ไม่ระบุ employee_code';
            } elseif (! $employee) {
                $rowErrors[] = "ไม่พบพนักงานรหัส {$code}";
            }

            // ข้ามพนักงานจ้างตามชิ้นงาน (งานเหมา) — ไม่เก็บเวลา คิดค่าจ้างตามการผลิต
            if ($employee && $pieceId !== null && (int) $employee->employment_type_id === (int) $pieceId) {
                $errors[] = ['row' => $rowNum, 'code' => (string) ($code ?? ''), 'errors' => ['ข้าม: พนักงานจ้างตามชิ้นงาน (งานเหมา) ไม่เก็บเวลา']];
                $skipped++;
                continue;
            }

            // ข้ามแผนกที่ตั้งค่าเป็น "ไม่บันทึกเวลา" (งานเหมาระดับแผนก)
            if ($employee && in_array((int) $employee->department_id, $noTrackDeptIds, true)) {
                $errors[] = ['row' => $rowNum, 'code' => (string) ($code ?? ''), 'errors' => ['ข้าม: แผนกงานเหมา ไม่บันทึกเวลา']];
                $skipped++;
                continue;
            }

            $rawDate = $assoc['date'] ?? null;
            $dateStr = $this->parseDate($rawDate);
            if ($rawDate === null) {
                $rowErrors[] = 'ไม่ระบุวันที่ (date)';
            } elseif ($dateStr === null) {
                $rowErrors[] = 'รูปแบบวันที่ (date) ไม่ถูกต้อง';
            }

            $rawIn = $assoc['check_in'] ?? null;
            $rawOut = $assoc['check_out'] ?? null;
            $inTime = $this->parseTime($rawIn);
            $outTime = $this->parseTime($rawOut);
            if ($rawIn !== null && $inTime === null) {
                $rowErrors[] = 'รูปแบบเวลาเข้า (check_in) ไม่ถูกต้อง';
            }
            if ($rawOut !== null && $outTime === null) {
                $rowErrors[] = 'รูปแบบเวลาออก (check_out) ไม่ถูกต้อง';
            }
            if ($inTime === null && $outTime === null && $rawIn === null && $rawOut === null) {
                $rowErrors[] = 'ต้องระบุเวลาเข้า (check_in) หรือเวลาออก (check_out) อย่างน้อย 1 ช่อง';
            }

            if (! empty($rowErrors)) {
                $errors[] = ['row' => $rowNum, 'code' => (string) ($code ?? ''), 'errors' => $rowErrors];
                $skipped++;
                continue;
            }

            $note = $assoc['note'] ?? null;
            $rowCreated = 0;

            try {
                DB::transaction(function () use ($employee, $dateStr, $inTime, $outTime, $note, $userId, &$rowCreated) {
                    if ($inTime !== null) {
                        $rowCreated += $this->createRecord($employee, 'check_in', Carbon::parse("$dateStr $inTime", self::TZ), $note, $userId) ? 1 : 0;
                    }
                    if ($outTime !== null) {
                        $rowCreated += $this->createRecord($employee, 'check_out', Carbon::parse("$dateStr $outTime", self::TZ), $note, $userId) ? 1 : 0;
                    }
                });
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNum, 'code' => (string) ($code ?? ''), 'errors' => [$e->getMessage()]];
                $skipped++;
                continue;
            }

            if ($rowCreated > 0) {
                $created += $rowCreated;
            } else {
                // เวลาเข้า/ออกซ้ำกับที่มีอยู่แล้วทั้งหมด → ข้าม
                $errors[] = ['row' => $rowNum, 'code' => (string) ($code ?? ''), 'errors' => ['มีบันทึกเวลาเดียวกันอยู่แล้ว (ข้าม)']];
                $skipped++;
            }
        }

        return response()->json([
            'message' => "นำเข้าสำเร็จ {$created} รายการ" . ($skipped > 0 ? " · ข้าม {$skipped} แถว" : ''),
            'summary' => [
                'created' => $created,
                'skipped' => $skipped,
                'total' => count($dataRows),
            ],
            'errors' => $errors,
        ]);
    }

    /**
     * สร้าง Attendance 1 record (พร้อมคำนวณสาย/ออกก่อน/OT และ audit log)
     * คืน false ถ้าซ้ำกับที่มีอยู่แล้ว (กันซ้ำ ±1 นาที)
     */
    private function createRecord(Employee $employee, string $type, Carbon $checkedAt, ?string $note, ?int $userId): bool
    {
        // $checkedAt เป็นเวลาท้องถิ่น (Asia/Bangkok) — เก็บลง DB เป็น UTC แต่คำนวณสถานะบนเวลาท้องถิ่น
        $utc = $checkedAt->copy()->utc();
        $tz = $checkedAt->getTimezone();

        // กันบันทึกซ้ำ: พนักงาน + ประเภทเดียวกัน ภายใน ±1 นาที
        $exists = Attendance::where('employee_id', $employee->id)
            ->where('type', $type)
            ->whereBetween('checked_at', [
                $utc->copy()->subMinute(),
                $utc->copy()->addMinute(),
            ])
            ->exists();
        if ($exists) {
            return false;
        }

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
                } elseif ($checkedAt->gt($shiftEnd->copy()->addMinutes(15)) && $this->schedule->allowsOt($employee)) {
                    $status = 'overtime';
                }
            }
        }

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'checked_at' => $utc,
            'work_shift_id' => $shift?->id,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'note' => $note,
            'source' => 'manual',
            'is_edited' => true,
            'edited_by' => $userId,
            'edited_at' => now(),
            'edit_reason' => 'นำเข้าจากไฟล์ Excel',
        ]);

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'action' => 'create',
            'old_values' => null,
            'new_values' => $attendance->only(['type', 'checked_at', 'status', 'late_minutes', 'work_shift_id', 'note']),
            'reason' => 'นำเข้าจากไฟล์ Excel',
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * แปลงค่าวันที่ (string / Excel serial / พ.ศ.) → 'Y-m-d'
     */
    private function parseDate($value): ?string
    {
        if ($value === null || $value === '') return null;

        $carbon = null;

        if (is_numeric($value)) {
            try {
                $carbon = Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                );
            } catch (\Throwable $e) {
                $carbon = null;
            }
        }

        if ($carbon === null) {
            try {
                $carbon = Carbon::parse($value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        // แปลงปีพุทธศักราช (พ.ศ. >= 2400) เป็นคริสต์ศักราชอัตโนมัติ
        if ($carbon->year >= 2400) {
            $carbon = $carbon->copy()->subYears(543);
        }

        return $carbon->format('Y-m-d');
    }

    /**
     * แปลงค่าเวลา (string "HH:MM" / Excel time fraction / datetime serial) → 'H:i:s'
     */
    private function parseTime($value): ?string
    {
        if ($value === null || $value === '') return null;

        if (is_numeric($value)) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
                return Carbon::instance($dt)->format('H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $s = trim((string) $value);
        // รับเฉพาะค่าที่มีรูปแบบเวลา (HH:MM[:SS]) หรือ datetime ที่มีวันที่
        if (preg_match('/\d{1,2}:\d{2}(:\d{2})?/', $s) || preg_match('/\d{4}-\d{1,2}-\d{1,2}/', $s)) {
            try {
                return Carbon::parse($s)->format('H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}

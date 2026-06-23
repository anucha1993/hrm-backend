<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\WorkProfile;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ศูนย์รวม logic การหา "ตารางการทำงาน" ของพนักงาน:
 *   - โปรไฟล์ที่ใช้  (รายคน > แผนก > ค่าเริ่มต้นบริษัท)
 *   - กะการทำงาน    (EmployeeShift รายคน > กะของโปรไฟล์)
 *   - วันหยุด        (วันหยุดของโปรไฟล์ > วันหยุดกลางทั้งบริษัท, รองรับยกเว้น)
 *
 * ใช้ร่วมกันโดย AttendanceController, AttendanceImportController, ImportTimesheet
 * เพื่อไม่ให้ logic ซ้ำซ้อน
 */
class WorkScheduleService
{
    /** cache โปรไฟล์ราย employee_id ภายในรอบการทำงานเดียว (สำคัญตอน import หลายพันแถว) */
    private array $profileCache = [];

    /** วันหยุดทั้งหมด (active) โหลดครั้งเดียว */
    private ?Collection $holidayCache = null;

    /** โปรไฟล์ค่าเริ่มต้นของบริษัท */
    private ?WorkProfile $defaultProfile = null;
    private bool $defaultLoaded = false;

    /**
     * หาโปรไฟล์การทำงานของพนักงาน: รายคน > แผนก > ค่าเริ่มต้นบริษัท
     */
    public function resolveProfile(Employee $employee): ?WorkProfile
    {
        $key = $employee->id;
        if (array_key_exists($key, $this->profileCache)) {
            return $this->profileCache[$key];
        }

        $profile = null;

        if ($employee->work_profile_id) {
            $profile = WorkProfile::with('workShift')->find($employee->work_profile_id);
        }

        if (! $profile && $employee->department_id) {
            $dept = $employee->relationLoaded('department')
                ? $employee->department
                : $employee->department()->first();
            if ($dept && $dept->work_profile_id) {
                $profile = WorkProfile::with('workShift')->find($dept->work_profile_id);
            }
        }

        if (! $profile) {
            $profile = $this->getDefaultProfile();
        }

        // ใช้เฉพาะโปรไฟล์ที่ active
        if ($profile && ! $profile->is_active) {
            $profile = null;
        }

        return $this->profileCache[$key] = $profile;
    }

    /**
     * หากะของพนักงาน ณ วันที่กำหนด
     *  1) EmployeeShift รายคน (มีช่วงวันที่ + work_days) — ความสำคัญสูงสุด
     *  2) กะของโปรไฟล์ (ตรวจ work_days ของโปรไฟล์)
     *  3) null = ไม่มีกะ (ลงเวลาได้ตลอดวัน)
     */
    public function resolveShift(Employee $employee, Carbon $when): ?WorkShift
    {
        $assignment = EmployeeShift::with('workShift')
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $when->toDateString())
            ->where(function ($q) use ($when) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $when->toDateString());
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if ($assignment && $assignment->workShift) {
            if ($this->matchesWorkDays($assignment->work_days, $when)) {
                return $assignment->workShift;
            }
            return null; // มีกะแต่ไม่ใช่วันทำงานของกะนั้น
        }

        // fallback → กะของโปรไฟล์
        $profile = $this->resolveProfile($employee);
        if ($profile && $profile->work_shift_id && $profile->workShift) {
            if ($this->matchesWorkDays($profile->work_days, $when)) {
                return $profile->workShift;
            }
            return null;
        }

        return null;
    }

    /**
     * วันที่นี้เป็นวันหยุดของพนักงานคนนี้หรือไม่
     * ลำดับ: วันหยุด/ยกเว้นเฉพาะโปรไฟล์ > วันหยุดกลางทั้งบริษัท
     */
    public function isHoliday(Employee $employee, Carbon $when): bool
    {
        $profile = $this->resolveProfile($employee);
        return $this->isHolidayForProfile($profile?->id, $when);
    }

    /**
     * วันที่นี้เป็นวันหยุดของโปรไฟล์ (หรือกลางทั้งบริษัทถ้า profileId = null) หรือไม่
     */
    public function isHolidayForProfile(?int $profileId, Carbon $when): bool
    {
        $holidays = $this->getHolidays();

        // 1) ตรวจ override เฉพาะโปรไฟล์ก่อน (ทั้งวันหยุด และยกเว้น)
        if ($profileId) {
            $profileMatches = $holidays
                ->where('work_profile_id', $profileId)
                ->filter(fn (Holiday $h) => $this->holidayMatchesDate($h, $when));

            if ($profileMatches->isNotEmpty()) {
                // ถ้ามีรายการ "ยกเว้น" (is_working=true) → วันนี้ทำงานปกติ
                if ($profileMatches->contains(fn (Holiday $h) => $h->is_working)) {
                    return false;
                }
                return true; // เป็นวันหยุดของโปรไฟล์
            }
        }

        // 2) วันหยุดกลางทั้งบริษัท (work_profile_id = null, is_working=false)
        $globalMatch = $holidays
            ->whereNull('work_profile_id')
            ->first(fn (Holiday $h) => ! $h->is_working && $this->holidayMatchesDate($h, $when));

        return $globalMatch !== null;
    }

    /**
     * รายการวันหยุดในช่วงวันที่ (สำหรับแสดงปฏิทิน / สรุป)
     * คืนเป็น array ของ ['date' => 'Y-m-d', 'name' => ...]
     */
    public function holidaysBetween(?int $profileId, Carbon $from, Carbon $to): array
    {
        $result = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if ($this->isHolidayForProfile($profileId, $cursor)) {
                $result[] = [
                    'date' => $cursor->toDateString(),
                    'name' => $this->holidayName($profileId, $cursor),
                ];
            }
            $cursor->addDay();
        }

        return $result;
    }

    /* ----------------- internal ----------------- */

    private function holidayName(?int $profileId, Carbon $when): ?string
    {
        $holidays = $this->getHolidays();

        if ($profileId) {
            $match = $holidays
                ->where('work_profile_id', $profileId)
                ->first(fn (Holiday $h) => ! $h->is_working && $this->holidayMatchesDate($h, $when));
            if ($match) {
                return $match->name;
            }
        }

        $global = $holidays
            ->whereNull('work_profile_id')
            ->first(fn (Holiday $h) => ! $h->is_working && $this->holidayMatchesDate($h, $when));

        return $global?->name;
    }

    private function holidayMatchesDate(Holiday $h, Carbon $when): bool
    {
        if (! $h->date) {
            return false;
        }
        if ($h->is_recurring) {
            return (int) $h->date->month === (int) $when->month
                && (int) $h->date->day === (int) $when->day;
        }
        return $h->date->isSameDay($when);
    }

    private function matchesWorkDays($days, Carbon $when): bool
    {
        if (! is_array($days) || count($days) === 0) {
            return true; // null/ว่าง = ทุกวัน
        }
        return in_array($when->dayOfWeekIso, array_map('intval', $days), true);
    }

    private function getHolidays(): Collection
    {
        if ($this->holidayCache === null) {
            $this->holidayCache = Holiday::where('is_active', true)->get();
        }
        return $this->holidayCache;
    }

    private function getDefaultProfile(): ?WorkProfile
    {
        if (! $this->defaultLoaded) {
            $this->defaultProfile = WorkProfile::with('workShift')
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();
            $this->defaultLoaded = true;
        }
        return $this->defaultProfile;
    }
}

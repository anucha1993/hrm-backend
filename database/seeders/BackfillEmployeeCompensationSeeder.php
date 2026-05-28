<?php

namespace Database\Seeders;

use App\Models\CompensationProfile;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * เติมโปรไฟล์ค่าจ้างพื้นฐานให้พนักงานที่ active แต่ยังไม่มี EmployeeCompensation
 * เพื่อให้รันคำนวณเงินเดือนได้ทุกคน
 */
class BackfillEmployeeCompensationSeeder extends Seeder
{
    public function run(): void
    {
        $profile = CompensationProfile::where('is_default', true)->first()
            ?? CompensationProfile::first();

        if (! $profile) {
            $this->command->error('ไม่พบ CompensationProfile กรุณารัน MasterDataSeeder ก่อน');
            return;
        }

        $defaultSalaryByType = [
            'รายเดือน'    => 15000,
            'รายวัน'      => 9750,   // ~325 บาท × 30
            'รายชั่วโมง'  => 8000,
            'สัญญาจ้าง'   => 18000,
            'ฝึกงาน'      => 6000,
        ];
        $defaultSalary = 15000;

        $effectiveFrom = Carbon::create(2026, 1, 1);
        $created = 0;
        $skipped = 0;

        foreach (Employee::with('employmentType')->where('status', Employee::STATUS_ACTIVE)->get() as $emp) {
            $has = EmployeeCompensation::where('employee_id', $emp->id)
                ->where('is_active', true)
                ->exists();
            if ($has) {
                $skipped++;
                continue;
            }

            $typeName = optional($emp->employmentType)->name;
            $salary = $defaultSalaryByType[$typeName] ?? $defaultSalary;

            EmployeeCompensation::create([
                'employee_id'             => $emp->id,
                'compensation_profile_id' => $profile->id,
                'base_salary'             => $salary,
                'effective_from'          => $effectiveFrom,
                'effective_to'            => null,
                'is_active'               => true,
            ]);
            $this->command->info("เพิ่ม {$emp->employee_code} ({$emp->first_name} {$emp->last_name}) — {$salary}");
            $created++;
        }

        $this->command->line("สร้างใหม่: {$created} รายการ | ข้าม (มีอยู่แล้ว): {$skipped} รายการ");
    }
}

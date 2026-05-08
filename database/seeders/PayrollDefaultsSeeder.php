<?php

namespace Database\Seeders;

use App\Models\CompensationProfile;
use App\Models\TaxBracket;
use App\Models\TaxProfile;
use Illuminate\Database\Seeder;

class PayrollDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        // ขั้นบันไดภาษีเงินได้บุคคลธรรมดา (TH)
        $brackets = [
            ['min_income' => 0,        'max_income' => 150000,  'rate' => 0],
            ['min_income' => 150000,   'max_income' => 300000,  'rate' => 5],
            ['min_income' => 300000,   'max_income' => 500000,  'rate' => 10],
            ['min_income' => 500000,   'max_income' => 750000,  'rate' => 15],
            ['min_income' => 750000,   'max_income' => 1000000, 'rate' => 20],
            ['min_income' => 1000000,  'max_income' => 2000000, 'rate' => 25],
            ['min_income' => 2000000,  'max_income' => 5000000, 'rate' => 30],
            ['min_income' => 5000000,  'max_income' => null,    'rate' => 35],
        ];
        foreach ($brackets as $i => $b) {
            TaxBracket::updateOrCreate(
                ['min_income' => $b['min_income'], 'max_income' => $b['max_income'], 'effective_year' => null],
                ['rate' => $b['rate'], 'order' => $i, 'is_active' => true]
            );
        }

        // โปรไฟล์ภาษีเริ่มต้น
        TaxProfile::updateOrCreate(
            ['name' => 'มาตรฐาน (โสด ไม่มีบุตร)'],
            [
                'description' => 'โปรไฟล์ภาษีเริ่มต้น — ค่าลดหย่อนส่วนตัว 60,000',
                'personal_allowance' => 60000,
                'expense_deduction_rate' => 50,
                'expense_deduction_max' => 100000,
                'is_default' => true,
                'is_active' => true,
            ]
        );

        // โปรไฟล์ค่าจ้างเริ่มต้น
        CompensationProfile::updateOrCreate(
            ['name' => 'พนักงานรายเดือน (มาตรฐาน)'],
            [
                'description' => 'โปรไฟล์เริ่มต้น 26 วัน/เดือน 8 ชม./วัน OT 1.5x',
                'pay_frequency' => 'monthly',
                'working_days_per_period' => 26,
                'working_hours_per_day' => 8,
                'ot_rate_normal' => 1.50,
                'ot_rate_holiday' => 2.00,
                'ot_rate_holiday_overtime' => 3.00,
                'late_deduction_method' => 'none',
                'late_deduction_rate' => 0,
                'late_grace_minutes' => 0,
                'absent_deduction_method' => 'daily_wage',
                'absent_deduction_amount' => 0,
                'ssf_enabled' => true,
                'ssf_rate' => 5.00,
                'ssf_min_base' => 1650,
                'ssf_max_base' => 15000,
                'is_default' => true,
                'is_active' => true,
            ]
        );
    }
}

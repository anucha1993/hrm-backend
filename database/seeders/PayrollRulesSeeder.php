<?php

namespace Database\Seeders;

use App\Models\PayrollRule;
use App\Models\PayrollSetting;
use Illuminate\Database\Seeder;

class PayrollRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            /* ===================== 🔻 DEDUCTIONS ===================== */
            [
                'code'              => 'DED-LATE-TIERED',
                'name'              => 'มาสาย (ขั้นบันได)',
                'type'              => 'deduction',
                'trigger'           => 'late_count',
                'accumulation_mode' => 'tiered',
                'tiers'             => [
                    ['threshold' => 3, 'amount' => 100],
                    ['threshold' => 5, 'amount' => 300],
                    ['threshold' => 7, 'amount' => 600],
                ],
                'amount_type' => 'fixed',
                'amount'      => 0, // ใช้ค่าใน tiers
                'period'      => 'monthly',
                'priority'    => 10,
                'note'        => 'หักตามจำนวนครั้งที่มาสายในรอบเดือน — สูงสุดเข้าขั้นใดให้คิดขั้นนั้น',
            ],
            [
                'code'              => 'DED-LATE-30MIN',
                'name'              => 'มาสายเกิน 30 นาที (ต่อครั้ง)',
                'type'              => 'deduction',
                'trigger'           => 'late_minutes',
                'accumulation_mode' => 'per_occurrence',
                'threshold'         => 30,
                'comparison'        => '>',
                'amount_type'       => 'per_occurrence',
                'amount'            => 50,
                'period'            => 'monthly',
                'priority'          => 20,
                'note'              => 'หัก 50 บาทต่อครั้งที่สายเกิน 30 นาที',
            ],
            [
                'code'              => 'DED-ABSENT',
                'name'              => 'ขาดงาน',
                'type'              => 'deduction',
                'trigger'           => 'absent_count',
                'accumulation_mode' => 'per_occurrence',
                'threshold'         => 1,
                'comparison'        => '>=',
                'amount_type'       => 'per_occurrence',
                'amount'            => 1500,
                'period'            => 'monthly',
                'priority'          => 5,
                'note'              => 'ขาด 1 วัน หัก 1,500 บาท',
            ],
            [
                'code'              => 'DED-ABSENT-DAILY-RATE',
                'name'              => 'ขาดงาน (คิดเป็นเรทรายวัน)',
                'type'              => 'deduction',
                'trigger'           => 'absent_count',
                'accumulation_mode' => 'per_occurrence',
                'threshold'         => 1,
                'comparison'        => '>=',
                'amount_type'       => 'daily_rate',
                'amount'            => 1, // ตัวคูณ × (salary/divisor)
                'period'            => 'monthly',
                'priority'          => 6,
                'active'            => false, // เผื่อเลือกใช้
                'note'              => 'ตัวเลือก: ขาด 1 วัน = หัก (เงินเดือน/30) × 1',
            ],
            [
                'code'              => 'DED-MISSING-PUNCH',
                'name'              => 'ลืมตอกบัตร',
                'type'              => 'deduction',
                'trigger'           => 'missing_punch',
                'accumulation_mode' => 'per_occurrence',
                'threshold'         => 1,
                'comparison'        => '>=',
                'amount_type'       => 'per_occurrence',
                'amount'            => 100,
                'period'            => 'monthly',
                'priority'          => 30,
                'note'              => 'ลืมตอกบัตรเข้า/ออก หักครั้งละ 100 บาท',
            ],
            [
                'code'              => 'DED-EARLY-LEAVE',
                'name'              => 'ออกก่อนเวลา',
                'type'              => 'deduction',
                'trigger'           => 'early_leave_count',
                'accumulation_mode' => 'per_occurrence',
                'threshold'         => 1,
                'comparison'        => '>=',
                'amount_type'       => 'per_occurrence',
                'amount'            => 100,
                'period'            => 'monthly',
                'priority'          => 35,
                'active'            => false,
                'note'              => 'ตัวเลือก: ออกก่อนเวลา หักครั้งละ 100 บาท',
            ],
            [
                'code'              => 'DED-LEAVE-OVER-QUOTA',
                'name'              => 'ลาเกินสิทธิ์',
                'type'              => 'deduction',
                'trigger'           => 'leave_over_quota',
                'accumulation_mode' => 'per_occurrence',
                'threshold'         => 1,
                'comparison'        => '>=',
                'amount_type'       => 'daily_rate',
                'amount'            => 1,
                'period'            => 'monthly',
                'priority'          => 40,
                'note'              => 'ลาเกินสิทธิ์ หักตามเรทรายวัน × จำนวนวัน',
            ],

            /* ===================== 🔺 BONUSES ===================== */
            [
                'code'              => 'BON-PERFECT-ATTENDANCE',
                'name'              => 'เบี้ยขยัน (ไม่ขาด ไม่สาย ไม่ลา)',
                'type'              => 'bonus',
                'trigger'           => 'no_disqualifier',
                'accumulation_mode' => 'one_shot',
                'amount_type'       => 'fixed',
                'amount'            => 1000,
                'disqualifiers'     => [
                    'absent', 'late', 'early_leave', 'missing_punch',
                    'leave_sick', 'leave_personal', 'leave_vacation',
                ],
                'period'   => 'monthly',
                'priority' => 100,
                'note'     => 'หากไม่มีเหตุการณ์ใดเหล่านี้ตลอดทั้งเดือน → +1,000',
            ],
            [
                'code'              => 'BON-PERFECT-LIGHT',
                'name'              => 'เบี้ยขยัน (อนุญาตลาป่วย/พักร้อนได้)',
                'type'              => 'bonus',
                'trigger'           => 'no_disqualifier',
                'accumulation_mode' => 'one_shot',
                'amount_type'       => 'fixed',
                'amount'            => 500,
                'disqualifiers'     => ['absent', 'late', 'missing_punch'],
                'period'            => 'monthly',
                'priority'          => 101,
                'active'            => false,
                'note'              => 'ตัวเลือก: ไม่ขาด/ไม่สาย/ไม่ลืมตอกบัตร (ลาป่วยมีใบรับรองได้) → +500',
            ],
            [
                'code'              => 'BON-RATING-HIGH',
                'name'              => 'โบนัสคะแนนงานเฉลี่ย ≥ 4.5 ดาว',
                'type'              => 'bonus',
                'trigger'           => 'rating_avg',
                'accumulation_mode' => 'one_shot',
                'threshold'         => 4, // เป็น integer; แต่ logic จะใช้ ≥4.5 ถ้าต้องการ ใช้ formula แทน
                'comparison'        => '>=',
                'amount_type'       => 'fixed',
                'amount'            => 500,
                'period'            => 'monthly',
                'priority'          => 110,
                'note'              => 'คะแนนงานเฉลี่ย ≥ 4 ดาว → +500 (ปรับ threshold ตามต้องการ)',
            ],
            [
                'code'              => 'BON-TENURE-YEAR',
                'name'              => 'โบนัสครบรอบงาน (ทุก 1 ปี)',
                'type'              => 'bonus',
                'trigger'           => 'tenure_years',
                'accumulation_mode' => 'repeating',
                'threshold'         => 1,
                'comparison'        => 'every',
                'amount_type'       => 'fixed',
                'amount'            => 1000,
                'period'            => 'yearly',
                'priority'          => 120,
                'note'              => 'ทุกๆ อายุงาน 1 ปีเพิ่ม +1,000 (จ่ายปีละครั้ง)',
            ],
            [
                'code'              => 'BON-OT-BIG',
                'name'              => 'โบนัส OT ≥ 40 ชม./เดือน',
                'type'              => 'bonus',
                'trigger'           => 'ot_hours',
                'accumulation_mode' => 'one_shot',
                'threshold'         => 40,
                'comparison'        => '>=',
                'amount_type'       => 'fixed',
                'amount'            => 800,
                'period'            => 'monthly',
                'priority'          => 130,
                'active'            => false,
                'note'              => 'ตัวเลือก: ทำ OT รวม ≥ 40 ชม./เดือน → +800',
            ],
        ];

        foreach ($rules as $r) {
            $code = $r['code'];
            unset($r['code']);
            PayrollRule::updateOrCreate(
                ['code' => $code],
                array_merge([
                    'accumulation_mode' => 'one_shot',
                    'comparison'        => '>=',
                    'amount_type'       => 'fixed',
                    'amount'            => 0,
                    'period'            => 'monthly',
                    'priority'          => 100,
                    'active'            => true,
                ], $r),
            );
        }

        /* ===================== ⚙️ GLOBAL SETTINGS ===================== */
        $defaults = [
            ['key' => 'max_deduction_percent', 'value' => 50,            'label' => 'หักรวมไม่เกิน % ของเงินเดือน'],
            ['key' => 'min_net_salary',        'value' => 0,             'label' => 'เงินสุทธิหลังหักขั้นต่ำ (บาท)'],
            ['key' => 'daily_rate_divisor',    'value' => 30,            'label' => 'ตัวหารเรทรายวัน (เงินเดือน/?)'],
            ['key' => 'calc_order',            'value' => 'deduct_first','label' => 'ลำดับการคำนวณ'],
        ];

        foreach ($defaults as $d) {
            PayrollSetting::updateOrCreate(
                ['key' => $d['key']],
                ['value' => $d['value'], 'label' => $d['label'], 'category' => 'global'],
            );
        }
    }
}

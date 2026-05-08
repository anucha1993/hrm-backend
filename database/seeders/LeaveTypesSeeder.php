<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'ANNUAL', 'name' => 'ลาพักร้อน', 'name_en' => 'Annual Leave',
                'color' => '#10b981', 'is_paid' => true, 'requires_approval' => true,
                'requires_attachment' => false, 'counts_as_workday' => true, 'affects_diligence' => false,
                'default_quota_days' => 6, 'min_advance_notice_days' => 3,
                'allow_half_day' => true, 'allow_negative_balance' => false,
                'order' => 1,
            ],
            [
                'code' => 'SICK', 'name' => 'ลาป่วย', 'name_en' => 'Sick Leave',
                'color' => '#ef4444', 'is_paid' => true, 'requires_approval' => true,
                'requires_attachment' => false, 'counts_as_workday' => true, 'affects_diligence' => true,
                'default_quota_days' => 30, 'min_advance_notice_days' => 0,
                'allow_half_day' => true, 'allow_negative_balance' => false,
                'description' => 'ลาป่วยเกิน 3 วันต้องแนบใบรับรองแพทย์',
                'order' => 2,
            ],
            [
                'code' => 'PERSONAL', 'name' => 'ลากิจส่วนตัว', 'name_en' => 'Personal Leave',
                'color' => '#f59e0b', 'is_paid' => true, 'requires_approval' => true,
                'requires_attachment' => false, 'counts_as_workday' => true, 'affects_diligence' => true,
                'default_quota_days' => 3, 'min_advance_notice_days' => 1,
                'allow_half_day' => true, 'allow_negative_balance' => false,
                'order' => 3,
            ],
            [
                'code' => 'MATERNITY', 'name' => 'ลาคลอด', 'name_en' => 'Maternity Leave',
                'color' => '#ec4899', 'is_paid' => true, 'requires_approval' => true,
                'requires_attachment' => true, 'counts_as_workday' => true, 'affects_diligence' => false,
                'default_quota_days' => 98, 'min_advance_notice_days' => 0,
                'allow_half_day' => false, 'allow_negative_balance' => false,
                'order' => 4,
            ],
            [
                'code' => 'ORDINATION', 'name' => 'ลาบวช', 'name_en' => 'Ordination Leave',
                'color' => '#f97316', 'is_paid' => false, 'requires_approval' => true,
                'requires_attachment' => false, 'counts_as_workday' => false, 'affects_diligence' => false,
                'default_quota_days' => 0, 'min_advance_notice_days' => 30,
                'allow_half_day' => false, 'allow_negative_balance' => true,
                'max_consecutive_days' => 120,
                'order' => 5,
            ],
            [
                'code' => 'UNPAID', 'name' => 'ลาไม่รับเงินเดือน', 'name_en' => 'Unpaid Leave',
                'color' => '#6b7280', 'is_paid' => false, 'requires_approval' => true,
                'requires_attachment' => false, 'counts_as_workday' => false, 'affects_diligence' => true,
                'default_quota_days' => 0, 'min_advance_notice_days' => 1,
                'allow_half_day' => true, 'allow_negative_balance' => true,
                'order' => 6,
            ],
            [
                'code' => 'BEREAVEMENT', 'name' => 'ลาเพื่อบุคคลในครอบครัวเสียชีวิต', 'name_en' => 'Bereavement Leave',
                'color' => '#475569', 'is_paid' => true, 'requires_approval' => true,
                'requires_attachment' => false, 'counts_as_workday' => true, 'affects_diligence' => false,
                'default_quota_days' => 3, 'min_advance_notice_days' => 0,
                'allow_half_day' => false, 'allow_negative_balance' => false,
                'max_consecutive_days' => 5,
                'order' => 7,
            ],
        ];

        foreach ($types as $t) {
            LeaveType::updateOrCreate(['code' => $t['code']], $t);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\WorkProfile;
use App\Models\WorkShift;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // โปรไฟล์ค่าเริ่มต้นของบริษัท (ใช้กะปกติถ้ามี)
        $defaultShift = WorkShift::where('is_active', true)->orderBy('id')->first();

        WorkProfile::firstOrCreate(
            ['is_default' => true],
            [
                'name'          => 'ค่าเริ่มต้นบริษัท',
                'work_shift_id' => $defaultShift?->id,
                'work_days'     => [1, 2, 3, 4, 5, 6], // จันทร์-เสาร์
                'description'   => 'โปรไฟล์การทำงานเริ่มต้น ใช้เมื่อแผนก/พนักงานไม่ได้กำหนดโปรไฟล์เฉพาะ',
                'is_default'    => true,
                'is_active'     => true,
            ]
        );

        // วันหยุดราชการไทย (แบบวันที่ตายตัว) — ตั้งเป็น "ซ้ำทุกปี"
        // หมายเหตุ: วันหยุดตามจันทรคติ (มาฆบูชา/วิสาขบูชา/อาสาฬหบูชา/เข้าพรรษา) เปลี่ยนทุกปี
        //          ให้ผู้ดูแลเพิ่มเองแบบ "วันที่เจาะจง" รายปี
        $year = now()->year;
        $fixed = [
            ['01-01', 'วันขึ้นปีใหม่'],
            ['04-06', 'วันจักรี'],
            ['04-13', 'วันสงกรานต์'],
            ['04-14', 'วันสงกรานต์'],
            ['04-15', 'วันสงกรานต์'],
            ['05-01', 'วันแรงงานแห่งชาติ'],
            ['05-04', 'วันฉัตรมงคล'],
            ['06-03', 'วันเฉลิมพระชนมพรรษา สมเด็จพระนางเจ้าฯ พระบรมราชินี'],
            ['07-28', 'วันเฉลิมพระชนมพรรษา พระบาทสมเด็จพระเจ้าอยู่หัว'],
            ['08-12', 'วันแม่แห่งชาติ'],
            ['10-13', 'วันคล้ายวันสวรรคต ร.9'],
            ['10-23', 'วันปิยมหาราช'],
            ['12-05', 'วันพ่อแห่งชาติ / วันชาติ'],
            ['12-10', 'วันรัฐธรรมนูญ'],
            ['12-31', 'วันสิ้นปี'],
        ];

        foreach ($fixed as [$md, $name]) {
            Holiday::firstOrCreate(
                [
                    'work_profile_id' => null,
                    'name'            => $name,
                    'date'            => "{$year}-{$md}",
                    'is_recurring'    => true,
                ],
                [
                    'is_working' => false,
                    'is_active'  => true,
                ]
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\ProductionRateItem;
use Illuminate\Database\Seeder;

class ProductionRateItemsSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // แพหน้า
            ['code' => 'PAE-FRONT-CAST', 'name' => 'แพหน้า เท',  'category' => 'pae_front', 'work_type' => 'cast', 'unit' => 'raft',  'target_qty' => 38,    'rate_at_target' => 1750, 'rate_below_target' => 1500],
            ['code' => 'PAE-FRONT-LIFT', 'name' => 'แพหน้า ยก',  'category' => 'pae_front', 'work_type' => 'lift', 'unit' => 'raft',  'target_qty' => 38,    'rate_at_target' => 1500, 'rate_below_target' => 1200],
            // แพหลัง
            ['code' => 'PAE-BACK-CAST',  'name' => 'แพหลัง เท',  'category' => 'pae_back',  'work_type' => 'cast', 'unit' => 'raft',  'target_qty' => 38,    'rate_at_target' => 1750, 'rate_below_target' => 1500],
            ['code' => 'PAE-BACK-LIFT',  'name' => 'แพหลัง ยก',  'category' => 'pae_back',  'work_type' => 'lift', 'unit' => 'raft',  'target_qty' => 38,    'rate_at_target' => 1500, 'rate_below_target' => 1200],
            // อัดแรง (เมตร)
            ['code' => 'PRESTRESS-CAST', 'name' => 'อัดแรง เท',  'category' => 'prestress', 'work_type' => 'cast', 'unit' => 'meter', 'target_qty' => 25000, 'rate_at_target' => 6.5,  'rate_below_target' => 6.0],
            ['code' => 'PRESTRESS-LIFT', 'name' => 'อัดแรง ยก',  'category' => 'prestress', 'work_type' => 'lift', 'unit' => 'meter', 'target_qty' => 25000, 'rate_at_target' => 6.5,  'rate_below_target' => 6.0],
            // ไอ 15
            ['code' => 'I15-CAST',       'name' => 'ไอ 15 เท',   'category' => 'i15',       'work_type' => 'cast', 'unit' => 'raft',  'target_qty' => 68,    'rate_at_target' => 1500, 'rate_below_target' => 1300],
            ['code' => 'I15-LIFT',       'name' => 'ไอ 15 ยก',   'category' => 'i15',       'work_type' => 'lift', 'unit' => 'raft',  'target_qty' => 68,    'rate_at_target' => 1500, 'rate_below_target' => 1300],
            // ไอ 18
            ['code' => 'I18-CAST',       'name' => 'ไอ 18 เท',   'category' => 'i18',       'work_type' => 'cast', 'unit' => 'raft',  'target_qty' => 68,    'rate_at_target' => 1980, 'rate_below_target' => 1780],
            ['code' => 'I18-LIFT',       'name' => 'ไอ 18 ยก',   'category' => 'i18',       'work_type' => 'lift', 'unit' => 'raft',  'target_qty' => 68,    'rate_at_target' => 1980, 'rate_below_target' => 1780],
            // เสารั้ว
            ['code' => 'FENCE-3X3',      'name' => 'เสารั้ว 3×3 เท+ยก', 'category' => 'fence', 'work_type' => 'cast_lift', 'unit' => 'raft', 'target_qty' => 30, 'rate_at_target' => 180, 'rate_below_target' => 135],
            ['code' => 'FENCE-4X4',      'name' => 'เสารั้ว 4×4 เท+ยก', 'category' => 'fence', 'work_type' => 'cast_lift', 'unit' => 'raft', 'target_qty' => 30, 'rate_at_target' => 240, 'rate_below_target' => 195],
            // เสาเข็ม (เหมา)
            ['code' => 'PILE-FLAT',      'name' => 'เสาเข็มธรรมดา (เหมา)', 'category' => 'pile', 'work_type' => 'flat', 'unit' => 'meter', 'target_qty' => null, 'rate_at_target' => 7, 'rate_below_target' => null, 'note' => 'เหมา 7 บาท/เมตร อัตราเดียว'],
        ];

        foreach ($items as $i => $row) {
            ProductionRateItem::updateOrCreate(
                ['code' => $row['code']],
                array_merge($row, ['sort_order' => $i + 1, 'is_active' => true])
            );
        }
    }
}

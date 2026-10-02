<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * สิทธิ์ลงย้อนหลังของใบลา / ใบมัดจำของใช้ — เพิ่มเข้า DB ตรงๆ แทนการรัน seeder
 * (seeder จะ sync สิทธิ์ของ role ทับที่ตั้งไว้ผ่านหน้าจัดการบทบาท)
 * ให้ super_admin / admin / hr ไว้ก่อน role อื่นไปติ๊กเพิ่มเองที่หน้าบทบาท
 */
return new class extends Migration
{
    private array $perms = [
        'leave.backdate' => ['ยื่นใบลาย้อนหลังได้ (เลือกวันที่ก่อนวันนี้)', 'leave'],
        'goods_deposits.backdate' => ['ลงใบมัดจำย้อนหลังได้ (เลือกวันที่ก่อนวันนี้)', 'goods_deposits'],
    ];

    public function up(): void
    {
        $now = now();
        $roleIds = DB::table('roles')->whereIn('name', ['super_admin', 'admin', 'hr'])->pluck('id');
        foreach ($this->perms as $name => [$display, $group]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $display, 'group' => $group, 'created_at' => $now, 'updated_at' => $now]
            );
            $permId = DB::table('permissions')->where('name', $name)->value('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->perms))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

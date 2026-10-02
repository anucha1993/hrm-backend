<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * สิทธิ์ลงงานย้อนหลัง (ใบจ่ายงาน/บันทึกผลรายวัน) — เพิ่มเข้า DB ตรงๆ แทนการรัน seeder
 * เพราะ seeder จะ sync สิทธิ์ของ role ทับที่ตั้งไว้ผ่านหน้าจัดการบทบาท
 * ให้ super_admin / admin / hr ไว้ก่อน role อื่นไปติ๊กเพิ่มเองที่หน้าบทบาท
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => 'production.backdate'],
            ['display_name' => 'ลงงาน/บันทึกผลรายวันย้อนหลังได้ (เลือกวันที่ก่อนวันนี้)', 'group' => 'production', 'created_at' => $now, 'updated_at' => $now]
        );
        $permId = DB::table('permissions')->where('name', 'production.backdate')->value('id');

        $roleIds = DB::table('roles')->whereIn('name', ['super_admin', 'admin', 'hr'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permId]);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'production.backdate')->value('id');
        if ($permId) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};

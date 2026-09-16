<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensations', function (Blueprint $t) {
            // ยอดหักประกันสังคมที่กรอกเอง (แทนการคำนวณอัตโนมัติจาก profile) — null = ใช้การคำนวณอัตโนมัติตามปกติ
            $t->decimal('ssf_manual_amount', 12, 2)->nullable()->after('hourly_rate_override');
            // true = ตัวเลขที่กรอกคือยอดรวมต่อเดือน ให้หารครึ่งหักต่องวด (งวดละ 2 ครั้ง/เดือน), false = หักเต็มจำนวนตามที่กรอกทุกงวด
            $t->boolean('ssf_manual_split_biweekly')->default(true)->after('ssf_manual_amount');
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->dropColumn(['ssf_manual_amount', 'ssf_manual_split_biweekly']);
        });
    }
};

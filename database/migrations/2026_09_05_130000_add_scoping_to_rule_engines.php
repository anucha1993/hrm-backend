<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            // null = ใช้กับทุกแผนก, array ของ department_id = ใช้เฉพาะแผนกที่ระบุ
            $table->json('department_ids')->nullable()->after('effective_to');
            // null = ใช้ได้ทุกงวด, array ของเลขเดือน 1-12 = ใช้เฉพาะงวดที่ครอบคลุมเดือนนั้น (เช่น โบนัสประจำปีที่จ่ายเฉพาะเดือนเมษายน)
            $table->json('apply_months')->nullable()->after('department_ids');
        });

        Schema::table('production_advance_rules', function (Blueprint $table) {
            // production_qty = เดิม (นับยอดผลิตจาก Work Order), attendance_days = นับจำนวนวันมาทำงานของพนักงานคนนั้นในงวดปัจจุบัน
            $table->string('metric_type', 20)->default('production_qty')->after('unit');
            // null = ใช้กับทุกแผนก (ตามเดิม), array ของ department_id = จำกัดเฉพาะพนักงานแผนกที่ระบุเท่านั้นที่ถูกเงื่อนไขนี้บังคับ
            // (แยกจาก scope/department_id เดิมซึ่งคุมว่ายอดสะสมนับจากแผนกไหน — อันนี้คุมว่า "ใครบ้าง" ที่โดนเงื่อนไขนี้)
            $table->json('applies_to_department_ids')->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->dropColumn(['department_ids', 'apply_months']);
        });
        Schema::table('production_advance_rules', function (Blueprint $table) {
            $table->dropColumn(['metric_type', 'applies_to_department_ids']);
        });
    }
};

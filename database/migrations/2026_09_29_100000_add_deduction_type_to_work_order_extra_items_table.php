<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_order_extra_items', function (Blueprint $table) {
            // ใช้จัดคอลัมน์ในใบพิมพ์ "ค่าหัก" ให้ตรงหมวดตายตัว (เงินเบิก/ค่าของ/ค่าไฟ/ประกัน) แทนการเดาจากชื่อ
            $table->string('deduction_type', 20)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_extra_items', function (Blueprint $table) {
            $table->dropColumn('deduction_type');
        });
    }
};

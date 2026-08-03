<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // รหัสลอตผลิต — ใส่ค่าเดียวกันในหลายใบงานเพื่อบอกว่าเป็นชุดผลิต (ลอต) เดียวกัน
            // เช่น แบ่งงาน ยก/เท บนชิ้นงานชุดเดียวกันคนละใบงาน แต่ผูกกันด้วยรหัสนี้
            $table->string('batch_code', 40)->nullable()->after('note');
            $table->index('batch_code');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['batch_code']);
            $table->dropColumn('batch_code');
        });
    }
};

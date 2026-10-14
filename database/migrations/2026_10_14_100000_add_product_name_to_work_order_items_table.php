<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ชื่อสินค้าที่ผลิตจริงในแต่ละรายการของใบงาน (ระบุเพิ่มใต้รายการเรท เช่น "เสาเข็มหกเหลี่ยม 6 เมตร")
 * — รายการเรทเดียวกันใช้ได้หลายแถวโดยแยกชื่อสินค้ากัน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->string('product_name', 255)->nullable()->after('production_rate_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('product_name');
        });
    }
};

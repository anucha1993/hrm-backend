<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_order_daily_entry_items', function (Blueprint $table) {
            // assigned = "สั่งให้ทำ" วันนั้น, actual = "ผลิตได้จริง" (กรอกตอนเย็น)
            $table->decimal('assigned_qty', 12, 2)->default(0)->after('work_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_daily_entry_items', function (Blueprint $table) {
            $table->dropColumn('assigned_qty');
        });
    }
};

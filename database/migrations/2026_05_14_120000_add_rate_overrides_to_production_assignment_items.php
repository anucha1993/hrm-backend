<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_assignment_items', function (Blueprint $table) {
            // override เรท: ถ้าเป็น null → ใช้เรทจาก production_rate_items
            $table->decimal('rate_at_target_override', 12, 2)->nullable()->after('actual_qty');
            $table->decimal('rate_below_target_override', 12, 2)->nullable()->after('rate_at_target_override');
        });
    }

    public function down(): void
    {
        Schema::table('production_assignment_items', function (Blueprint $table) {
            $table->dropColumn(['rate_at_target_override', 'rate_below_target_override']);
        });
    }
};

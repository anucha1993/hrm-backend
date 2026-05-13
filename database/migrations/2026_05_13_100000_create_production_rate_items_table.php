<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_rate_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();           // e.g. PAE-FRONT-CAST
            $table->string('name', 200);                    // ชื่อรายการ เช่น "แพหน้า เท"
            $table->string('category', 50)->nullable();     // กลุ่ม: pae_front, pae_back, prestress, i15, i18, fence, pile, etc.
            $table->enum('work_type', ['cast', 'lift', 'cast_lift', 'flat'])->default('cast');
            //  cast = เท | lift = ยก | cast_lift = เท+ยก (เสารั้ว) | flat = อัตราเดียว (เสาเข็ม)
            $table->enum('unit', ['raft', 'meter'])->default('raft');
            // raft = แพ | meter = เมตร
            $table->decimal('target_qty', 12, 2)->nullable();   // จำนวนเป้า (null = flat)
            $table->decimal('rate_at_target', 12, 2);            // เรทถึงเป้า
            $table->decimal('rate_below_target', 12, 2)->nullable(); // เรทไม่ถึงเป้า (null = flat)
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_rate_items');
    }
};

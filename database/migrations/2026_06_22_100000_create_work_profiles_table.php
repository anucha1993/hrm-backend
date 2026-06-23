<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // โปรไฟล์การทำงาน = ชุดกะ + วันทำงาน + ปฏิทินวันหยุด (ผูกกับแผนก/รายคนได้)
        Schema::create('work_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // เช่น "พนักงานออฟฟิศ จ-ศ"
            $table->foreignId('work_shift_id')->nullable()
                ->constrained('work_shifts')->nullOnDelete();        // กะเริ่มต้นของโปรไฟล์
            $table->json('work_days')->nullable();                   // [1..7] (1=จันทร์); null = ทุกวัน
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);           // โปรไฟล์ค่าเริ่มต้นของบริษัท (มีได้ตัวเดียว)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_profiles');
    }
};

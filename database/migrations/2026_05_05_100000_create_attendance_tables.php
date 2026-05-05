<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // สถานที่ทำงาน (รองรับหลายสาขา + กำหนดรัศมี geofence)
        Schema::create('office_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->default(100);   // รัศมี (เมตร) เมื่อเปิด geofence
            $table->boolean('enforce_geofence')->default(true);  // false = อนุญาตทุกที่
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // กะการทำงาน
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                           // เช่น "กะปกติ 8:00-17:00"
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('break_minutes')->default(60);   // เวลาพัก (นาที)
            $table->unsignedInteger('late_grace_minutes')->default(15);  // อนุโลมสาย
            $table->boolean('cross_midnight')->default(false);  // กะข้ามวัน
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // มอบหมายกะให้พนักงาน (ถ้าไม่มีในตารางนี้ = ไม่มีกะ ลงเวลาได้ตลอดวัน)
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('work_shift_id')->constrained('work_shifts')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('work_days')->nullable(); // [1,2,3,4,5] = จ-ศ; null = ทุกวัน
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // บันทึกการลงเวลา
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('type', ['check_in', 'check_out']);
            $table->dateTime('checked_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();   // ความแม่นยำ GPS (เมตร)
            $table->foreignId('office_location_id')->nullable()->constrained('office_locations')->nullOnDelete();
            $table->decimal('distance_m', 10, 2)->nullable(); // ระยะห่างจาก office (เมตร)
            $table->boolean('outside_geofence')->default(false);
            $table->foreignId('work_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();
            $table->enum('status', ['normal', 'late', 'early_leave', 'overtime'])->default('normal');
            $table->unsignedInteger('late_minutes')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('work_shifts');
        Schema::dropIfExists('office_locations');
    }
};

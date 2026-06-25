<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รูปแบบการหมุนเวียนกะ (rotation pattern) — ลำดับกะในหนึ่งรอบ
        Schema::create('shift_rotations', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // เช่น "หมุน 3 กะ รายสัปดาห์"
            $table->json('sequence');               // [shift_id, shift_id, null, ...] null = วันหยุดของช่วงนั้น
            $table->unsignedInteger('days_per_step')->default(7); // เปลี่ยนกะทุกกี่วัน (7 = รายสัปดาห์)
            $table->date('anchor_date');            // วันเริ่มรอบ (ช่อง index 0 ของ sequence)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // มอบหมายพนักงานเข้ารอบหมุนเวียน + offset (ทำให้แต่ละคนเหลื่อมกะกัน)
        Schema::create('employee_rotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_rotation_id')->constrained('shift_rotations')->cascadeOnDelete();
            $table->unsignedInteger('offset')->default(0); // เริ่มที่ index ไหนของ sequence
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // คำขอสลับกะ (workflow: ขอ -> อนุมัติ/ปฏิเสธ)
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('employees')->cascadeOnDelete();      // ผู้ขอ
            $table->foreignId('counterparty_id')->constrained('employees')->cascadeOnDelete();    // คู่สลับ
            $table->date('requester_date');     // วันของผู้ขอที่จะสลับ
            $table->date('counterparty_date');  // วันของคู่สลับ (เท่ากับ requester_date = สลับกะวันเดียวกัน)
            $table->foreignId('requester_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();    // กะเดิมผู้ขอ (snapshot)
            $table->foreignId('counterparty_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete(); // กะเดิมคู่สลับ (snapshot)
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index(['requester_id', 'requester_date']);
        });

        // กะเฉพาะรายวัน (override) — ความสำคัญสูงสุดในการ resolve กะ
        Schema::create('shift_day_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('work_shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();
            $table->boolean('is_day_off')->default(false); // true = วันนี้หยุด (ชนะทุกกะ)
            $table->enum('source', ['manual', 'swap', 'rotation_exception'])->default('manual');
            $table->foreignId('shift_swap_request_id')->nullable()->constrained('shift_swap_requests')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']); // 1 คน 1 วัน = 1 override
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_day_overrides');
        Schema::dropIfExists('shift_swap_requests');
        Schema::dropIfExists('employee_rotations');
        Schema::dropIfExists('shift_rotations');
    }
};

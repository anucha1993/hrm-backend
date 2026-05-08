<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('color', 20)->default('#3b82f6');
            $t->boolean('is_paid')->default(true);                    // ลามีค่าจ้าง vs ลาไม่รับเงิน
            $t->boolean('requires_approval')->default(true);
            $t->boolean('requires_attachment')->default(false);
            $t->boolean('counts_as_workday')->default(true);          // นับเป็นวันทำงานในงวด หรือไม่
            $t->boolean('affects_diligence')->default(false);         // ทำลายเบี้ยขยันไหม
            $t->decimal('default_quota_days', 6, 2)->default(0);      // โควต้าเริ่มต้นต่อปี
            $t->integer('min_advance_notice_days')->default(0);       // ต้องยื่นล่วงหน้ากี่วัน
            $t->boolean('allow_half_day')->default(true);
            $t->boolean('allow_negative_balance')->default(false);
            $t->integer('max_consecutive_days')->nullable();
            $t->text('description')->nullable();
            $t->integer('order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('leave_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $t->integer('year');
            $t->decimal('quota_days', 6, 2)->default(0);              // สิทธิ์ปีนั้น
            $t->decimal('carryover_days', 6, 2)->default(0);          // ยกมาจากปีก่อน
            $t->decimal('used_days', 6, 2)->default(0);               // ใช้ไปแล้ว (อนุมัติแล้ว)
            $t->decimal('pending_days', 6, 2)->default(0);            // รออนุมัติ
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->string('request_no', 30)->unique();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('is_half_day')->default(false);
            $t->enum('half_day_period', ['morning', 'afternoon'])->nullable();
            $t->decimal('total_days', 6, 2);                          // คำนวณตอนสร้าง
            $t->text('reason')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['employee_id', 'start_date']);
            $t->index('status');
        });

        Schema::create('leave_request_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $t->string('action', 30);                                  // submit/approve/reject/cancel
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_logs');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // หัวงาน (1 ทีม / 1 วัน) — จ่ายเงินให้หัวหน้าทีมคนเดียว
        Schema::create('production_assignments', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->foreignId('team_leader_id')->constrained('employees')->restrictOnDelete();
            $table->string('location_name', 200)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0); // sum items.total_amount
            $table->enum('status', ['draft', 'in_progress', 'completed', 'paid'])->default('draft');
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_date', 'status']);
            $table->index('payroll_period_id');
            $table->index('team_leader_id');
        });

        // รายการผลิตในงาน (1 งาน → N items) — กำหนด target & actual; ระบบคิดเรทอัตโนมัติ
        Schema::create('production_assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_rate_item_id')->constrained()->restrictOnDelete();
            $table->decimal('target_qty', 12, 2);          // จำนวนที่ต้องผลิต (ผู้ใช้กำหนด)
            $table->decimal('actual_qty', 12, 2)->default(0); // จำนวนที่ผลิตจริง (กรอกตอนปิดงาน)
            $table->decimal('rate_used', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0); // actual_qty × rate_used
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('production_assignment_id', 'pai_assignment_idx');
            $table->index('production_rate_item_id', 'pai_rate_item_idx');
        });

        // ลูกทีม (record-only — ดูประวัติว่าวันนั้นในทีมมีใครบ้าง ไม่คิดเงิน)
        Schema::create('production_assignment_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_assignment_id');
            $table->foreign('production_assignment_id', 'pam_assignment_fk')
                ->references('id')->on('production_assignments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('role', 50)->nullable(); // เช่น 'caster','lifter' หรือว่าง
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['production_assignment_id', 'employee_id'], 'pam_assignment_employee_unique');
            $table->index('employee_id', 'pam_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_assignment_members');
        Schema::dropIfExists('production_assignment_items');
        Schema::dropIfExists('production_assignments');
    }
};



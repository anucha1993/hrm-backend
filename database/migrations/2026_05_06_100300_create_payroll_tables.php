<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // งวดจ่ายเงิน — ผู้ดูแลสร้างเอง (manual) มีได้หลายงวดต่อเดือน
        Schema::create('payroll_periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');                // เช่น "งวด 1 พ.ย. 2569"
            $t->string('code')->unique();      // เช่น "2026-11-A"
            $t->date('start_date');
            $t->date('end_date');
            $t->date('pay_date');
            $t->enum('status', ['draft', 'computing', 'pending_l1', 'pending_l2', 'approved', 'paid', 'cancelled'])->default('draft');
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->index(['start_date', 'end_date']);
        });

        // สลิปเงินเดือน — 1 employee : 1 period
        Schema::create('payroll_slips', function (Blueprint $t) {
            $t->id();
            $t->string('slip_no')->unique();
            $t->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();

            // snapshot โปรไฟล์ ณ ขณะคำนวณ (ป้องกันค่า profile ถูกแก้แล้วกระทบสลิปเก่า)
            $t->foreignId('compensation_profile_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('tax_profile_id')->nullable()->constrained()->nullOnDelete();
            $t->json('profile_snapshot')->nullable();
            $t->json('tax_snapshot')->nullable();

            // ฐานคำนวณ
            $t->decimal('base_salary', 12, 2)->default(0);
            $t->decimal('hourly_rate', 12, 2)->default(0);
            $t->decimal('daily_rate', 12, 2)->default(0);

            // ข้อมูลจากการลงเวลา
            $t->unsignedSmallInteger('working_days')->default(0);
            $t->unsignedSmallInteger('present_days')->default(0);
            $t->unsignedSmallInteger('absent_days')->default(0);
            $t->unsignedSmallInteger('leave_days')->default(0);
            $t->unsignedInteger('late_count')->default(0);
            $t->unsignedInteger('late_minutes_total')->default(0);
            $t->decimal('ot_hours_total', 8, 2)->default(0);

            // ยอดรวม
            $t->decimal('base_pay', 12, 2)->default(0);
            $t->decimal('ot_pay', 12, 2)->default(0);
            $t->decimal('allowances_total', 12, 2)->default(0);
            $t->decimal('bonus_total', 12, 2)->default(0);
            $t->decimal('gross_pay', 12, 2)->default(0);

            $t->decimal('late_deduction', 12, 2)->default(0);
            $t->decimal('absent_deduction', 12, 2)->default(0);
            $t->decimal('other_deductions_total', 12, 2)->default(0);
            $t->decimal('ssf_employee', 12, 2)->default(0);
            $t->decimal('ssf_employer', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('deductions_total', 12, 2)->default(0);

            $t->decimal('net_pay', 12, 2)->default(0);

            // workflow
            $t->enum('status', [
                'draft', 'computed', 'pending_l1', 'pending_l2',
                'approved', 'paid', 'rejected', 'cancelled',
            ])->default('draft');
            $t->foreignId('approved_l1_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_l1_at')->nullable();
            $t->foreignId('approved_l2_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_l2_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_reference')->nullable();
            $t->text('note')->nullable();
            $t->json('calculation_log')->nullable();
            $t->timestamps();

            $t->unique(['payroll_period_id', 'employee_id']);
            $t->index(['employee_id', 'status']);
        });

        // รายการในสลิป (รายละเอียดแบบ line items)
        Schema::create('payroll_slip_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_slip_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['earning', 'deduction', 'tax', 'ssf', 'info']);
            $t->enum('source', ['base', 'rule', 'component', 'attendance', 'ot', 'tax_calc', 'manual'])->default('manual');
            $t->string('code')->nullable();
            $t->string('name');
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('quantity', 10, 2)->nullable();
            $t->decimal('rate', 12, 2)->nullable();
            $t->boolean('taxable')->default(false);
            $t->boolean('affects_ssf')->default(false);
            $t->string('formula')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->unsignedSmallInteger('order')->default(0);
            $t->timestamps();
            $t->index(['payroll_slip_id', 'type']);
        });

        // ประวัติการอนุมัติ / เปลี่ยนสถานะ
        Schema::create('payroll_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_slip_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('payroll_period_id')->nullable()->constrained()->cascadeOnDelete();
            $t->enum('action', [
                'compute', 'submit_l1', 'approve_l1', 'reject_l1',
                'submit_l2', 'approve_l2', 'reject_l2',
                'mark_paid', 'cancel', 'reopen',
            ]);
            $t->string('from_status')->nullable();
            $t->string('to_status')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        // เพิ่ม FK ของ ot_session_employees → payroll_slips
        Schema::table('ot_session_employees', function (Blueprint $t) {
            $t->foreign('payroll_slip_id')->references('id')->on('payroll_slips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ot_session_employees', function (Blueprint $t) {
            $t->dropForeign(['payroll_slip_id']);
        });
        Schema::dropIfExists('payroll_approvals');
        Schema::dropIfExists('payroll_slip_items');
        Schema::dropIfExists('payroll_slips');
        Schema::dropIfExists('payroll_periods');
    }
};

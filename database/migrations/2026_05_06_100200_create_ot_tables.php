<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // รอบ OT — ผู้ดูแลเปิด OT แต่ละวัน
        Schema::create('ot_sessions', function (Blueprint $t) {
            $t->id();
            $t->date('ot_date');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->enum('ot_type', ['normal', 'holiday', 'holiday_overtime'])->default('normal');
            // วิธีคิดเงิน OT
            $t->enum('rate_mode', ['hourly_amount', 'multiplier'])->default('hourly_amount');
            $t->decimal('hourly_amount', 12, 2)->default(0);   // ใช้กับ hourly_amount: ทุกคน rate เท่ากัน
            $t->decimal('multiplier', 5, 2)->default(1.50);    // ใช้กับ multiplier: rate = hourly_rate ของพนักงาน × multiplier
            $t->string('description')->nullable();
            $t->enum('status', ['draft', 'open', 'closed'])->default('open');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('ot_date');
        });

        // พนักงานที่เข้าร่วม OT รอบนั้น พร้อมจำนวน ชม.
        Schema::create('ot_session_employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ot_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->decimal('hours', 6, 2)->default(0);
            $t->decimal('hourly_rate_snapshot', 12, 2)->default(0); // เก็บ snapshot อัตราขณะนั้น
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('note')->nullable();
            $t->foreignId('payroll_slip_id')->nullable(); // จะ FK หลังสร้าง payroll_slips
            $t->timestamps();
            $t->unique(['ot_session_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ot_session_employees');
        Schema::dropIfExists('ot_sessions');
    }
};

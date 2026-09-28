<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ค่าคอมมิชชั่นลอย ๆ — สร้างเรคคอร์ดเองแล้วดึงเข้าเงินเดือนอัตโนมัติ (pull-based เหมือน goods_deposit_slips)
        Schema::create('commissions', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();          // เช่น COMM-2609-0001
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('earned_date');
            $t->decimal('amount', 12, 2);
            $t->string('title')->nullable();       // เช่น "ค่าคอมขายเดือน ก.ย."
            $t->text('note')->nullable();

            $t->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');

            $t->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payslip_id')->nullable()->constrained('payroll_slips')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['employee_id', 'status']);
            $t->index(['earned_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ใบมัดจำของใช้ทั่วไป — "หยิบก่อน จ่ายทีหลัง" หักผ่าน payroll
        Schema::create('goods_deposit_slips', function (Blueprint $t) {
            $t->id();
            $t->string('slip_no')->unique();              // เช่น GD-2606-0001
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('deposit_date');
            $t->decimal('total_amount', 12, 2)->default(0);

            $t->enum('status', ['pending', 'deducted', 'cancelled', 'waived'])->default('pending');

            // อ้างถึง payroll ที่ใช้หัก (ถ้าหักไปแล้ว)
            $t->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payslip_id')->nullable()->constrained('payroll_slips')->nullOnDelete();
            $t->timestamp('deducted_at')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['employee_id', 'status']);
            $t->index(['deposit_date', 'status']);
        });

        Schema::create('goods_deposit_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deposit_slip_id')->constrained('goods_deposit_slips')->cascadeOnDelete();
            $t->string('item_name');
            $t->decimal('qty', 10, 2)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('note')->nullable();
            $t->unsignedSmallInteger('order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_deposit_items');
        Schema::dropIfExists('goods_deposit_slips');
    }
};

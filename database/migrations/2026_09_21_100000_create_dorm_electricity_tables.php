<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ห้องพัก — เก็บเลขมิเตอร์ล่าสุด (ต่อยอดเดือนถัดไป) + ผู้เช่าปัจจุบัน
        Schema::create('dorm_rooms', function (Blueprint $t) {
            $t->id();
            $t->string('room_no', 30)->unique();
            $t->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete(); // ผู้เช่าปัจจุบัน
            $t->decimal('rent_amount', 12, 2)->default(500);       // ค่าห้องมาตรฐาน
            $t->decimal('last_meter_reading', 12, 2)->default(0);  // เลขมิเตอร์ล่าสุด (ยกไปเป็นเลขก่อนของเดือนถัดไป)
            $t->boolean('is_active')->default(true);
            $t->string('note', 255)->nullable();
            $t->timestamps();

            $t->index('is_active');
        });

        // ใบค่าไฟรายเดือน (1 รอบบิล)
        Schema::create('electricity_bills', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();      // เช่น ELEC-2026-09
            $t->date('bill_month');                // วันที่ 1 ของเดือนที่คิดบิล
            $t->decimal('rate_per_unit', 10, 2)->default(4);
            $t->enum('status', ['draft', 'finalized'])->default('draft');
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('finalized_at')->nullable();
            $t->timestamps();

            $t->index('bill_month');
        });

        // รายการต่อห้องในแต่ละบิล
        Schema::create('electricity_bill_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('electricity_bill_id')->constrained()->cascadeOnDelete();
            $t->foreignId('dorm_room_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete(); // ผู้เช่า ณ ตอนออกบิล (snapshot)
            $t->decimal('meter_start', 12, 2)->default(0);
            $t->decimal('meter_end', 12, 2)->nullable();
            $t->decimal('units_used', 12, 2)->default(0);
            $t->decimal('rate_per_unit', 10, 2)->default(4);
            $t->decimal('electricity_amount', 12, 2)->default(0);
            $t->decimal('room_rent', 12, 2)->default(500);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->unsignedSmallInteger('order')->default(0);
            $t->timestamps();

            $t->unique(['electricity_bill_id', 'dorm_room_id']);
        });

        // งวดหักเงินเดือน (แบ่งจ่าย 2 งวด/เดือน ให้ตรงกับรอบเงินเดือนแบบ 2 ครั้ง/เดือน)
        Schema::create('electricity_bill_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('electricity_bill_item_id')->constrained('electricity_bill_items')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->unsignedTinyInteger('installment_no'); // 1 หรือ 2
            $t->date('due_date');                      // ใช้จับคู่กับงวดเงินเดือน (start_date..end_date)
            $t->decimal('amount', 12, 2);
            $t->enum('status', ['pending', 'deducted', 'cancelled', 'waived'])->default('pending');
            $t->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payslip_id')->nullable()->constrained('payroll_slips')->nullOnDelete();
            $t->timestamp('deducted_at')->nullable();
            $t->timestamps();

            $t->index(['employee_id', 'status']);
            $t->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electricity_bill_installments');
        Schema::dropIfExists('electricity_bill_items');
        Schema::dropIfExists('electricity_bills');
        Schema::dropIfExists('dorm_rooms');
    }
};

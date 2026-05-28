<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 200);
            // 'deduction' = หัก, 'bonus' = เพิ่ม
            $table->enum('type', ['deduction', 'bonus']);
            // เงื่อนไขที่ใช้ trigger:
            //   late_count        - จำนวนครั้งที่มาสายในรอบ
            //   late_minutes      - นาทีรวมที่มาสายในรอบ
            //   absent_count      - จำนวนวันขาดงาน
            //   early_leave_count - จำนวนครั้งออกก่อนเวลา
            //   missing_punch     - จำนวนครั้งลืมตอกบัตร
            //   no_disqualifier   - ไม่มีเหตุเสียสิทธิ์ (เบี้ยขยัน)
            //   rating_avg        - คะแนนงานเฉลี่ย
            //   tenure_years      - อายุงานครบ X ปี
            //   ot_hours          - ชั่วโมง OT
            //   leave_over_quota  - ลาเกินสิทธิ์
            $table->string('trigger', 40);
            // accumulation_mode: repeating | one_shot | tiered | per_occurrence
            $table->enum('accumulation_mode', ['repeating', 'one_shot', 'tiered', 'per_occurrence'])
                  ->default('one_shot');
            $table->integer('threshold')->nullable(); // ใช้กับ repeating/one_shot/per_occurrence
            $table->string('comparison', 5)->default('>='); // >= | > | = | every
            $table->json('tiers')->nullable(); // [{threshold:int, amount:number}]
            // amount_type: fixed | per_occurrence | percent_salary | daily_rate | formula
            $table->enum('amount_type', ['fixed', 'per_occurrence', 'percent_salary', 'daily_rate', 'formula'])
                  ->default('fixed');
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('formula')->nullable();
            // disqualifiers สำหรับ trigger=no_disqualifier
            // ['absent','late','early_leave','missing_punch','leave_sick','leave_personal','leave_vacation','leave_maternity','leave_other']
            $table->json('disqualifiers')->nullable();
            $table->decimal('min_per_period', 12, 2)->nullable();
            $table->decimal('max_per_period', 12, 2)->nullable();
            $table->enum('period', ['monthly', 'yearly', 'period'])->default('monthly');
            $table->integer('priority')->default(100);
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'active']);
            $table->index('trigger');
        });

        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->string('category', 50)->default('general');
            $table->string('label', 200)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
        Schema::dropIfExists('payroll_rules');
    }
};

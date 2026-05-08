<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ขั้นบันไดภาษี (ผู้ดูแลแก้ไขได้)
        Schema::create('tax_brackets', function (Blueprint $t) {
            $t->id();
            $t->decimal('min_income', 14, 2);
            $t->decimal('max_income', 14, 2)->nullable(); // null = ไม่จำกัด
            $t->decimal('rate', 5, 2); // %
            $t->unsignedSmallInteger('order')->default(0);
            $t->year('effective_year')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['effective_year', 'order']);
        });

        // โปรไฟล์ค่าลดหย่อนภาษี (ใช้ซ้ำ — ตั้งเป็นเทมเพลต)
        Schema::create('tax_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('description')->nullable();
            $t->decimal('personal_allowance', 12, 2)->default(60000);
            $t->decimal('spouse_allowance', 12, 2)->default(0);
            $t->unsignedSmallInteger('children_count')->default(0);
            $t->decimal('child_allowance_each', 12, 2)->default(30000);
            $t->decimal('parent_allowance', 12, 2)->default(0);
            $t->decimal('disabled_allowance', 12, 2)->default(0);
            $t->decimal('life_insurance', 12, 2)->default(0);
            $t->decimal('health_insurance', 12, 2)->default(0);
            $t->decimal('provident_fund', 12, 2)->default(0);
            $t->decimal('rmf_amount', 12, 2)->default(0);
            $t->decimal('ssf_amount', 12, 2)->default(0); // SSF investment ไม่ใช่ประกันสังคม
            $t->decimal('home_loan_interest', 12, 2)->default(0);
            $t->decimal('donation_amount', 12, 2)->default(0);
            $t->json('extra_deductions')->nullable(); // [{name, amount}]
            $t->decimal('expense_deduction_rate', 5, 2)->default(50); // % ค่าใช้จ่ายเหมา (50% สูงสุด 100k)
            $t->decimal('expense_deduction_max', 12, 2)->default(100000);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        // ตั้งค่าภาษีรายคน
        Schema::create('employee_tax_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tax_profile_id')->nullable()->constrained()->nullOnDelete();
            // วิธีคำนวณ
            $t->enum('tax_method', ['progressive', 'fixed_rate', 'flat_amount', 'none'])->default('progressive');
            $t->decimal('fixed_rate', 5, 2)->default(0);     // % ใช้กับ fixed_rate
            $t->decimal('flat_amount', 12, 2)->default(0);   // หักตายตัวต่องวด
            $t->enum('withhold_strategy', ['annualize', 'per_period'])->default('annualize');
            // override allowance รายคน
            $t->json('overrides')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_settings');
        Schema::dropIfExists('tax_profiles');
        Schema::dropIfExists('tax_brackets');
    }
};

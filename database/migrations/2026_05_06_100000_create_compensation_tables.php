<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // โปรไฟล์ค่าจ้าง
        Schema::create('compensation_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('description')->nullable();
            $t->enum('pay_frequency', ['monthly', 'biweekly', 'weekly', 'daily'])->default('monthly');
            $t->unsignedSmallInteger('working_days_per_period')->default(26);
            $t->unsignedSmallInteger('working_hours_per_day')->default(8);
            // อัตรา OT (เท่าของค่าจ้างต่อ ชม.) — ใช้กรณี admin ไม่ระบุใน OT session
            $t->decimal('ot_rate_normal', 5, 2)->default(1.50);
            $t->decimal('ot_rate_holiday', 5, 2)->default(2.00);
            $t->decimal('ot_rate_holiday_overtime', 5, 2)->default(3.00);
            // วิธีหักสาย
            $t->enum('late_deduction_method', ['none', 'per_minute', 'per_hour', 'per_incident', 'fixed'])->default('none');
            $t->decimal('late_deduction_rate', 10, 2)->default(0);
            $t->unsignedSmallInteger('late_grace_minutes')->default(0);
            // วิธีหักขาดงาน
            $t->enum('absent_deduction_method', ['none', 'daily_wage', 'fixed'])->default('daily_wage');
            $t->decimal('absent_deduction_amount', 10, 2)->default(0);
            // ประกันสังคม
            $t->boolean('ssf_enabled')->default(true);
            $t->decimal('ssf_rate', 5, 2)->default(5.00);          // %
            $t->decimal('ssf_min_base', 12, 2)->default(1650);
            $t->decimal('ssf_max_base', 12, 2)->default(15000);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        // กฎพิเศษภายใน profile (rule engine)
        Schema::create('profile_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('compensation_profile_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            // เงื่อนไข
            $t->enum('trigger', [
                'absent_count', 'late_count', 'late_minutes_total',
                'present_days', 'continuous_present_days',
                'ot_hours_total',
            ]);
            $t->enum('operator', ['eq', 'lte', 'gte', 'lt', 'gt', 'between']);
            $t->decimal('threshold', 12, 2)->default(0);
            $t->decimal('threshold_max', 12, 2)->nullable(); // for between
            // การกระทำ
            $t->enum('action', ['add_bonus', 'add_deduction', 'add_allowance']);
            $t->enum('amount_type', ['fixed', 'percent_of_base'])->default('fixed');
            $t->decimal('amount', 12, 2)->default(0);
            $t->enum('scope', ['this_period', 'year_to_date'])->default('this_period');
            $t->boolean('taxable')->default(false);
            $t->boolean('affects_ssf')->default(false);
            $t->unsignedSmallInteger('priority')->default(100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // เทมเพลต allowance / deduction (ใช้ซ้ำ)
        Schema::create('compensation_components', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->enum('kind', ['allowance', 'deduction']);
            $t->decimal('default_amount', 12, 2)->default(0);
            $t->boolean('taxable')->default(true);          // นำมารวมในฐานคำนวณภาษีไหม
            $t->boolean('affects_ssf')->default(true);      // นำมารวมในฐานคำนวณ SSF ไหม
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ผูก profile เข้ากับ employee + override base salary ได้
        Schema::create('employee_compensations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('compensation_profile_id')->constrained()->restrictOnDelete();
            $t->decimal('base_salary', 12, 2);
            $t->decimal('hourly_rate_override', 12, 2)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['employee_id', 'effective_from']);
        });

        // เบี้ย/หัก รายคน (เช่น เงินกู้บริษัท ผ่อน 12 งวด)
        Schema::create('employee_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('compensation_component_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->unsignedSmallInteger('total_installments')->nullable();
            $t->unsignedSmallInteger('paid_installments')->default(0);
            $t->string('note')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['employee_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_components');
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('compensation_components');
        Schema::dropIfExists('profile_rules');
        Schema::dropIfExists('compensation_profiles');
    }
};

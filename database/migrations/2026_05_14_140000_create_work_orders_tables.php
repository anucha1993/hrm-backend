<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop Phase 4 tables
        Schema::dropIfExists('production_assignment_members');
        Schema::dropIfExists('production_assignment_items');
        Schema::dropIfExists('production_assignments');

        // ใบจ่ายงาน
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // WO-2026051001
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('period_type', ['daily', 'biweekly_1', 'biweekly_2', 'monthly', 'custom'])->default('custom');
            $table->foreignId('team_leader_id')->constrained('employees')->restrictOnDelete();
            $table->string('location_name', 200)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->enum('status', ['draft', 'in_progress', 'completed', 'paid'])->default('draft');
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['start_date', 'end_date']);
            $table->index('status');
            $table->index('payroll_period_id');
            $table->index('team_leader_id');
        });

        // เป้าผลิตของใบจ่ายงาน
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->foreign('work_order_id', 'woi_work_order_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreignId('production_rate_item_id')->constrained()->restrictOnDelete();
            $table->decimal('target_qty', 12, 2);
            $table->decimal('actual_qty_total', 12, 2)->default(0); // คำนวณจาก daily entries
            $table->decimal('rate_at_target_override', 12, 2)->nullable();
            $table->decimal('rate_below_target_override', 12, 2)->nullable();
            $table->decimal('rate_used', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('work_order_id', 'woi_wo_idx');
            $table->index('production_rate_item_id', 'woi_rate_idx');
        });

        // ลูกทีม
        Schema::create('work_order_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->foreign('work_order_id', 'wom_work_order_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('role', 50)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'employee_id'], 'wom_wo_emp_unique');
            $table->index('employee_id', 'wom_emp_idx');
        });

        // จ่ายงานรายวัน (ต่อใบจ่ายงาน)
        Schema::create('work_order_daily_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->foreign('work_order_id', 'wode_work_order_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'work_date'], 'wode_wo_date_unique');
            $table->index('work_date');
        });

        // ผลผลิตรายวันต่อ item
        Schema::create('work_order_daily_entry_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_daily_entry_id');
            $table->foreign('work_order_daily_entry_id', 'wodei_entry_fk')
                ->references('id')->on('work_order_daily_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('work_order_item_id');
            $table->foreign('work_order_item_id', 'wodei_item_fk')
                ->references('id')->on('work_order_items')->cascadeOnDelete();
            $table->decimal('actual_qty', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['work_order_daily_entry_id', 'work_order_item_id'], 'wodei_entry_item_unique');
            $table->index('work_order_item_id', 'wodei_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_daily_entry_items');
        Schema::dropIfExists('work_order_daily_entries');
        Schema::dropIfExists('work_order_members');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
    }
};

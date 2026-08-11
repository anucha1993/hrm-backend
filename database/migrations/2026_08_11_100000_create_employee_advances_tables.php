<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $t) {
            $t->id();
            $t->string('request_no', 30)->unique();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->text('reason')->nullable();
            $t->date('request_date');
            $t->decimal('repaid_amount', 12, 2)->default(0);          // ยอดหักคืนสะสม
            $t->enum('status', ['pending', 'approved', 'rejected', 'paid', 'completed', 'cancelled'])->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('approval_note')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['employee_id', 'status']);
            $t->index('status');
        });

        Schema::create('employee_advance_repayments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_advance_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2);
            $t->date('repaid_at');
            $t->text('note')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advance_repayments');
        Schema::dropIfExists('employee_advances');
    }
};

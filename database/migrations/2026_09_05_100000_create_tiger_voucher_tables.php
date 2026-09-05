<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_advance_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('unit', 30)->default('raft');
            $table->decimal('target_qty', 14, 2);
            $table->enum('scope', ['company', 'department'])->default('company');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('production_advance_rule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_advance_rule_id')->constrained('production_advance_rules')->cascadeOnDelete();
            $table->foreignId('production_rate_item_id')->constrained('production_rate_items')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['production_advance_rule_id', 'production_rate_item_id'], 'prod_adv_rule_item_unique');
        });

        Schema::table('employee_advances', function (Blueprint $table) {
            $table->enum('disbursement_method', ['manual', 'tiger_voucher'])->default('manual')->after('status');
            $table->foreignId('production_advance_rule_id')->nullable()->after('disbursement_method')
                ->constrained('production_advance_rules')->nullOnDelete();
            $table->string('tiger_voucher_code', 64)->nullable()->after('production_advance_rule_id');
            $table->string('tiger_voucher_ref_num', 64)->nullable()->after('tiger_voucher_code');
            $table->string('tiger_voucher_status', 20)->nullable()->after('tiger_voucher_ref_num');
            $table->json('tiger_voucher_response')->nullable()->after('tiger_voucher_status');
            $table->timestamp('tiger_voucher_issued_at')->nullable()->after('tiger_voucher_response');
        });
    }

    public function down(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_advance_rule_id');
            $table->dropColumn([
                'disbursement_method', 'tiger_voucher_code', 'tiger_voucher_ref_num',
                'tiger_voucher_status', 'tiger_voucher_response', 'tiger_voucher_issued_at',
            ]);
        });
        Schema::dropIfExists('production_advance_rule_items');
        Schema::dropIfExists('production_advance_rules');
    }
};

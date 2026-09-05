<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->boolean('eligibility_bypassed')->default(false)->after('tiger_voucher_issued_at');
            $table->text('eligibility_bypass_reason')->nullable()->after('eligibility_bypassed');
            $table->foreignId('eligibility_bypass_by')->nullable()->after('eligibility_bypass_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('eligibility_bypass_at')->nullable()->after('eligibility_bypass_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eligibility_bypass_by');
            $table->dropColumn(['eligibility_bypassed', 'eligibility_bypass_reason', 'eligibility_bypass_at']);
        });
    }
};

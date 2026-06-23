<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ผูกโปรไฟล์การทำงานกับแผนก (ค่าเริ่มต้น) และพนักงาน (override รายคน)
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('work_profile_id')->nullable()->after('description')
                ->constrained('work_profiles')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('work_profile_id')->nullable()->after('department_id')
                ->constrained('work_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['work_profile_id']);
            $table->dropColumn('work_profile_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['work_profile_id']);
            $table->dropColumn('work_profile_id');
        });
    }
};

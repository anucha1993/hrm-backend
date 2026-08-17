<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // รหัสพนักงานที่ enroll ไว้ในเครื่องสแกน HIP Time (ผูกกับ enrollnumber ของ Transcantime)
            $table->string('hip_enroll_number', 50)->nullable()->unique()->after('labour_id');
        });

        Schema::table('attendances', function (Blueprint $table) {
            // อ้างอิงแถวต้นทางจากระบบภายนอก (เช่น "hiptime:123") กันนำเข้าซ้ำเวลา sync ทับช่วงเดิม
            $table->string('source_ref', 100)->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('hip_enroll_number');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('source_ref');
        });
    }
};

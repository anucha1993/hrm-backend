<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // full = สแกนเข้า+ออกตามปกติ, check_in_only = สแกนเฉพาะเข้างาน, none = ไม่บันทึกเวลา (งานเหมา)
            $table->string('attendance_mode', 20)->default('full')->after('work_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('attendance_mode');
        });
    }
};

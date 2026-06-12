<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * อนุญาตให้ birth_date และ national_id เป็นค่าว่างได้
     * เพื่อรองรับการนำเข้าพนักงานที่ยังเก็บเอกสารไม่ครบ (เช่น ยังไม่มีเลขบัตร/วันเกิด)
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // คงชนิดข้อมูลเดิมไว้ (national_id = varchar(20) รองรับ passport) เพิ่มแค่ nullable
            $table->date('birth_date')->nullable()->change();
            $table->string('national_id', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('birth_date')->nullable(false)->change();
            $table->string('national_id', 20)->nullable(false)->change();
        });
    }
};

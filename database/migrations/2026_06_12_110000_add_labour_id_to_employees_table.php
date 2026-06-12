<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * เพิ่มคอลัมน์ labour_id สำหรับเชื่อมพนักงานต่างด้าวกับระบบ Labour
     * (คนไทยจะเป็น null, ต่างด้าวที่ match กับ Labour API จะมีค่า)
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'labour_id')) {
                $table->unsignedBigInteger('labour_id')
                    ->nullable()
                    ->after('national_id')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'labour_id')) {
                $table->dropColumn('labour_id');
            }
        });
    }
};

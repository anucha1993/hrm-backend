<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // ขยายให้รองรับทั้งเลขบัตรประชาชน 13 หลัก และเลข passport (alphanumeric)
            $table->string('national_id', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->char('national_id', 13)->change();
        });
    }
};

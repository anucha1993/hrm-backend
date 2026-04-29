<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique(); // ISO 3166-1 alpha-2/3
            $table->string('name');
            $table->string('nationality')->nullable(); // เช่น "ไทย"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');                  // รายเดือน / รายวัน / รายชั่วโมง / สัญญาจ้าง / ฝึกงาน
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('departments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_relatives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('relative_employee_id');
            $table->string('relation', 100);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->foreign('employee_id', 'emprel_employee_fk')
                ->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('relative_employee_id', 'emprel_relative_fk')
                ->references('id')->on('employees')->cascadeOnDelete();
            $table->unique(['employee_id', 'relative_employee_id'], 'emprel_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_relatives');
    }
};

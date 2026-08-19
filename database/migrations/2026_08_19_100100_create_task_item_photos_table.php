<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_item_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_item_id');
            $table->foreign('task_item_id', 'tip_item_fk')
                ->references('id')->on('task_items')->cascadeOnDelete();
            $table->enum('kind', ['before', 'after']);
            $table->string('photo_path', 500);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete(); // ผู้อัปโหลด
            $table->timestamps();

            $table->index(['task_item_id', 'kind'], 'tip_item_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_item_photos');
    }
};

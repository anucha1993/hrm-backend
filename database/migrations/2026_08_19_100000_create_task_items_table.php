<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id', 'ti_task_fk')
                ->references('id')->on('tasks')->cascadeOnDelete();
            $table->string('title', 255); // งานย่อย เช่น "เก็บขยะหน้าโรงงาน"
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'sort_order'], 'ti_task_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_items');
    }
};

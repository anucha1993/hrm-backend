<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // TSK-2026052200001
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->date('due_date')->nullable();
            $table->enum('status', ['open', 'in_progress', 'submitted', 'completed', 'cancelled'])->default('open');
            $table->string('location_name', 200)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('due_date');
        });

        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id', 'ta_task_fk')
                ->references('id')->on('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id', 'ta_employee_fk')
                ->references('id')->on('employees')->restrictOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'submitted', 'approved', 'rejected'])->default('pending');
            $table->string('before_photo_path', 500)->nullable();
            $table->string('after_photo_path', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('submit_note')->nullable();
            // คะแนนที่ admin ให้ (per assignee)
            $table->unsignedTinyInteger('rating')->nullable(); // 1-5
            $table->text('rating_note')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'employee_id'], 'ta_task_employee_uq');
            $table->index('employee_id', 'ta_employee_idx');
            $table->index('status', 'ta_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('tasks');
    }
};

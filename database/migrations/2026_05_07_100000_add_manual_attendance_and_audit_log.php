<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // เพิ่มฟิลด์เพื่อบ่งชี้ว่าเป็นการเพิ่ม/แก้ไขแบบ manual
        Schema::table('attendances', function (Blueprint $t) {
            $t->enum('source', ['device', 'manual'])->default('device')->after('note');
            $t->boolean('is_edited')->default(false)->after('source');
            $t->foreignId('edited_by')->nullable()->after('is_edited')
                ->constrained('users')->nullOnDelete();
            $t->timestamp('edited_at')->nullable()->after('edited_by');
            $t->string('edit_reason', 500)->nullable()->after('edited_at');
        });

        // ตาราง log การแก้ไข — เก็บ old/new ทุกครั้งที่ HR แก้
        Schema::create('attendance_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->enum('action', ['create', 'update', 'delete']);
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('reason', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['attendance_id']);
            $t->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropConstrainedForeignId('edited_by');
            $t->dropColumn(['source', 'is_edited', 'edited_at', 'edit_reason']);
        });
    }
};

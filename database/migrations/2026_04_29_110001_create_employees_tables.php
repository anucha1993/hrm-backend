<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 50)->unique();    // รหัสพนักงาน
            $table->enum('title', ['นาย', 'นางสาว', 'นาง']);    // คำนำหน้า
            $table->string('first_name');
            $table->string('last_name');
            $table->string('nickname')->nullable();
            $table->date('birth_date');
            $table->enum('gender', ['M', 'F', 'Other']);
            $table->string('phone', 15)->nullable();
            $table->string('email')->nullable()->unique();
            $table->text('address')->nullable();
            $table->char('national_id', 13)->unique();        // เลขบัตรประชาชน
            $table->string('marital_status')->nullable();     // โสด/สมรส/หย่า/หม้าย
            $table->string('religion')->nullable();
            $table->string('education_level')->nullable();    // ระดับการศึกษา

            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained('employment_types')->nullOnDelete();

            $table->string('position')->nullable();           // ตำแหน่งงาน
            $table->date('hire_date')->nullable();            // วันที่เริ่มงาน
            $table->date('resign_date')->nullable();          // วันที่ลาออก
            $table->decimal('base_salary', 12, 2)->nullable();// เงินเดือน/ค่าจ้างฐาน

            // ข้อมูลธนาคาร
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no', 30)->nullable();
            $table->string('bank_account_name')->nullable();

            // ผู้ติดต่อกรณีฉุกเฉิน
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_relation')->nullable();
            $table->string('emergency_contact_phone', 15)->nullable();

            $table->enum('status', ['active', 'resigned', 'terminated', 'suspended'])->default('active');
            // active=ทำงาน, resigned=ลาออก, terminated=เลิกจ้าง, suspended=พักงาน

            $table->string('avatar_path')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // เผื่อผูกกับบัญชีผู้ใช้
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'department_id']);
            $table->index('first_name');
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name');           // ชื่อเอกสาร เช่น "สำเนาบัตรประชาชน"
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employees');
    }
};

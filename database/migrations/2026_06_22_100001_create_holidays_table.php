<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // วันหยุด — work_profile_id = null คือวันหยุดกลางทั้งบริษัท
        // ถ้าระบุ work_profile_id = วันหยุด/ยกเว้นเฉพาะโปรไฟล์นั้น
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_profile_id')->nullable()
                ->constrained('work_profiles')->cascadeOnDelete();   // null = วันหยุดกลาง
            $table->string('name');                                  // เช่น "วันสงกรานต์"
            $table->date('date');                                    // วันที่ (recurring จะเทียบเดือน-วัน)
            $table->boolean('is_recurring')->default(false);         // true = ซ้ำทุกปี
            $table->boolean('is_working')->default(false);           // true = ยกเว้น (วันนี้ทำงานปกติ) override วันหยุดกลาง
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['work_profile_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};

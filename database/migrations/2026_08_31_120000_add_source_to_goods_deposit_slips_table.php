<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('goods_deposit_slips', function (Blueprint $t) {
            // แยกใบที่พนักงานสร้างเองใน HRM กับใบที่ระบบสร้างอัตโนมัติจาก labour-app-importer
            $t->enum('source', ['manual', 'labour_api'])->default('manual')->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('goods_deposit_slips', function (Blueprint $t) {
            $t->dropColumn('source');
        });
    }
};

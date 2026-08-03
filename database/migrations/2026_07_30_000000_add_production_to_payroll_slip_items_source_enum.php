<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payroll_slip_items MODIFY source ENUM('base', 'rule', 'component', 'attendance', 'ot', 'tax_calc', 'manual', 'production') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payroll_slip_items MODIFY source ENUM('base', 'rule', 'component', 'attendance', 'ot', 'tax_calc', 'manual') NOT NULL DEFAULT 'manual'");
    }
};

<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WipeEmployeesCommand extends Command
{
    protected $signature = 'employees:wipe
                            {--force : ข้ามการยืนยัน}
                            {--keep-users : เก็บบัญชี User ของพนักงานไว้ (ไม่ลบ)}';

    protected $description = 'ล้างข้อมูลพนักงานทั้งหมด รวมข้อมูลที่เกี่ยวข้อง (attendance/leave/payroll/tasks/work orders/goods deposits/wage profiles) — ใช้ก่อน import ข้อมูลจริง';

    public function handle(): int
    {
        // เก็บ user_ids ของพนักงานก่อน
        $employeeUserIds = Employee::whereNotNull('user_id')->pluck('user_id')->all();
        $employeeCount   = Employee::count();

        $this->info("พบพนักงาน {$employeeCount} คน (ผูกกับ User account " . count($employeeUserIds) . " บัญชี)");

        if (! $this->option('force')) {
            if (! $this->confirm('ยืนยันการลบข้อมูลทั้งหมดของพนักงาน? (ลบแล้วเรียกคืนไม่ได้)')) {
                $this->warn('ยกเลิก');
                return self::SUCCESS;
            }
        }

        // ตารางที่ผูก employee_id แบบ RESTRICT — ต้องล้างก่อน
        // ลำดับล้าง: ตารางย่อยที่อ้างถึงตารางหลัก → ตารางหลัก → employees → users
        $tables = [
            // Goods deposits
            'goods_deposit_items',
            'goods_deposit_slips',

            // Payroll
            'payroll_slip_items',
            'payroll_approvals',
            'payroll_slips',

            // OT pivot
            'ot_session_employees',

            // Work orders
            'work_order_daily_entry_items',
            'work_order_daily_entries',
            'work_order_items',
            'work_order_members',
            'work_orders',

            // Production assignments (legacy)
            'production_assignment_members',
            'production_assignment_items',
            'production_assignments',

            // Tasks
            'task_assignees',
            'tasks',

            // Leave
            'leave_request_logs',
            'leave_requests',
            'leave_balances',

            // Compensation / Tax (employee-level)
            'employee_components',
            'employee_compensations',
            'employee_tax_settings',

            // Attendance
            'attendance_audit_logs',
            'attendances',
            'employee_shifts',

            // Employee misc
            'employee_documents',
            'employees',
        ];

        $this->info('กำลังปิด FK checks ชั่วคราว...');
        Schema::disableForeignKeyConstraints();

        // หมายเหตุ: ใน MySQL คำสั่ง TRUNCATE ทำ implicit commit
        // จึงห้ามครอบ DB::transaction (จะเกิด "no active transaction" error)
        foreach ($tables as $tbl) {
            if (! Schema::hasTable($tbl)) {
                $this->line("  - ข้าม {$tbl} (ไม่มีตารางนี้)");
                continue;
            }
            $count = DB::table($tbl)->count();
            DB::table($tbl)->truncate();
            $this->line("  - ล้าง {$tbl}: {$count} รายการ");
        }

        Schema::enableForeignKeyConstraints();

        // ลบ User accounts ของพนักงาน (เฉพาะที่ role = employee เพื่อกันลบ admin โดยไม่ตั้งใจ)
        if (! $this->option('keep-users')) {
            $employeeRole = Role::where('name', Role::EMPLOYEE)->first();
            if ($employeeRole && count($employeeUserIds) > 0) {
                $deleted = User::whereIn('id', $employeeUserIds)
                    ->where('role_id', $employeeRole->id)
                    ->delete();
                $this->info("ลบบัญชี User ของพนักงาน {$deleted} บัญชี");
            }
        } else {
            $this->info('ข้ามการลบ User accounts (--keep-users)');
        }

        $this->newLine();
        $this->info('✓ ล้างข้อมูลพนักงานเรียบร้อย — พร้อม import ข้อมูลจริงได้แล้ว');
        return self::SUCCESS;
    }
}

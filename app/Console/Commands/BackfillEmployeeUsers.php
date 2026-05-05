<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class BackfillEmployeeUsers extends Command
{
    protected $signature = 'employees:backfill-users {--dry-run : แสดงผลโดยไม่บันทึก}';

    protected $description = 'สร้าง/ผูกบัญชี User ให้พนักงานที่ยังไม่มี user_id (Username=รหัสพนักงาน, Password=เลข ปปช.)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $role = Role::where('name', Role::EMPLOYEE)->first();
        if (! $role) {
            $this->error('ไม่พบ Role "employee" — กรุณารัน php artisan db:seed --class=RolesAndPermissionsSeeder ก่อน');
            return self::FAILURE;
        }

        $targets = Employee::whereNull('user_id')->get();
        $this->info("พบพนักงานที่ยังไม่มี user_id: {$targets->count()} คน" . ($dry ? ' [DRY-RUN]' : ''));

        $created = 0;
        $linked  = 0;
        foreach ($targets as $emp) {
            if (! $emp->employee_code || ! $emp->national_id) {
                $this->warn("- ข้าม #{$emp->id} {$emp->first_name} {$emp->last_name}: ขาด employee_code หรือ national_id");
                continue;
            }
            $email = strtolower($emp->employee_code) . '@cyc-hrm.local';
            $existing = User::where('email', $email)->first();

            if ($existing) {
                if (! $dry) $emp->update(['user_id' => $existing->id]);
                $linked++;
                $this->line("- ผูก #{$emp->id} ({$emp->employee_code}) → user #{$existing->id}");
                continue;
            }

            if ($dry) {
                $this->line("- จะสร้าง user สำหรับ #{$emp->id} ({$emp->employee_code})");
                $created++;
                continue;
            }

            $user = User::create([
                'name'      => trim($emp->first_name . ' ' . $emp->last_name) ?: $emp->employee_code,
                'email'     => $email,
                'password'  => $emp->national_id, // hashed อัตโนมัติ
                'role_id'   => $role->id,
                'is_active' => true,
            ]);
            $emp->update(['user_id' => $user->id]);
            $created++;
            $this->line("- สร้าง user #{$user->id} ({$email}) สำหรับ #{$emp->id} ({$emp->employee_code})");
        }

        $this->info("เสร็จสิ้น: สร้างใหม่ {$created}, ผูกกับที่มีอยู่ {$linked}");
        return self::SUCCESS;
    }
}

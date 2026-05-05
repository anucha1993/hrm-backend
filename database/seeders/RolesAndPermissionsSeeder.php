<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions ตามกลุ่มเมนู
        $permissionGroups = [
            'users' => [
                'users.view'   => 'ดูผู้ใช้',
                'users.create' => 'เพิ่มผู้ใช้',
                'users.update' => 'แก้ไขผู้ใช้',
                'users.delete' => 'ลบผู้ใช้',
            ],
            'roles' => [
                'roles.view'   => 'ดูบทบาท/สิทธิ์',
                'roles.create' => 'เพิ่มบทบาท',
                'roles.update' => 'แก้ไขบทบาท',
                'roles.delete' => 'ลบบทบาท',
            ],
            'employees' => [
                'employees.view'   => 'ดูพนักงาน',
                'employees.create' => 'เพิ่มพนักงาน',
                'employees.update' => 'แก้ไขพนักงาน',
                'employees.delete' => 'ลบพนักงาน',
            ],
            'master_data' => [
                'master_data.manage' => 'จัดการข้อมูลหลัก (แผนก/ประเทศ/ประเภทการจ้าง)',
            ],
            'labours' => [
                'labours.view' => 'ดูข้อมูลแรงงานต่างด้าว (Labour API)',
            ],
            'attendance' => [
                'attendance.checkin' => 'ลงเวลาเข้า-ออก',
                'attendance.view'    => 'ดูประวัติเวลางาน (รวมพนักงานทุกคน)',
                'attendance.manage'  => 'จัดการเวลางาน (สถานที่/กะ/แก้ไขข้อมูล)',
            ],
            'tasks' => [
                'tasks.view'   => 'ดูงาน',
                'tasks.manage' => 'จัดการงาน',
            ],
            'payroll' => [
                'payroll.view'    => 'ดูเงินเดือน',
                'payroll.compute' => 'คำนวณเงินเดือน',
                'payroll.approve' => 'อนุมัติเงินเดือน',
            ],
            'reports' => [
                'reports.view' => 'ดูรายงาน',
            ],
            'settings' => [
                'settings.view'   => 'ดูตั้งค่าระบบ',
                'settings.manage' => 'จัดการตั้งค่าระบบ',
            ],
        ];

        $allPermissions = [];
        foreach ($permissionGroups as $group => $items) {
            foreach ($items as $name => $display) {
                $perm = Permission::updateOrCreate(
                    ['name' => $name],
                    ['display_name' => $display, 'group' => $group]
                );
                $allPermissions[$name] = $perm;
            }
        }

        // Roles
        $superAdmin = Role::updateOrCreate(
            ['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'description' => 'ผู้ดูแลระบบสูงสุด', 'is_system' => true]
        );
        $admin = Role::updateOrCreate(
            ['name' => Role::ADMIN],
            ['display_name' => 'Admin', 'description' => 'ผู้ดูแลระบบ', 'is_system' => true]
        );
        $member = Role::updateOrCreate(
            ['name' => Role::MEMBER],
            ['display_name' => 'Member', 'description' => 'ผู้ใช้งานทั่วไป', 'is_system' => true]
        );
        $employee = Role::updateOrCreate(
            ['name' => Role::EMPLOYEE],
            ['display_name' => 'Employee', 'description' => 'พนักงาน (ลงเวลา/ดูประวัติของตน)', 'is_system' => true]
        );

        // Super admin มีทุก permission
        $superAdmin->permissions()->sync(collect($allPermissions)->pluck('id')->all());

        // Admin: ทุกสิทธิ์ ยกเว้นจัดการ Roles
        $adminPerms = collect($allPermissions)
            ->reject(fn ($p) => str_starts_with($p->name, 'roles.'))
            ->pluck('id')->all();
        // ให้ admin ดู role ได้ แต่แก้ไม่ได้
        $adminPerms[] = $allPermissions['roles.view']->id;
        $admin->permissions()->sync(array_unique($adminPerms));

        // Member: ดูข้อมูลพื้นฐาน + งาน + เวลางานของตน
        $memberPerms = [
            'employees.view',
            'attendance.checkin',
            'attendance.view',
            'tasks.view',
            'payroll.view',
            'reports.view',
        ];
        $member->permissions()->sync(
            collect($memberPerms)->map(fn ($n) => $allPermissions[$n]->id)->all()
        );

        // Employee: เฉพาะลงเวลาเข้า-ออก ของตนเอง
        $employeePerms = [
            'attendance.checkin',
        ];
        $employee->permissions()->sync(
            collect($employeePerms)->map(fn ($n) => $allPermissions[$n]->id)->all()
        );

        // Default Super Admin
        User::updateOrCreate(
            ['email' => 'superadmin@cyc-hrm.local'],
            [
                'name'      => 'Super Admin',
                'password'  => 'password',
                'role_id'   => $superAdmin->id,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@cyc-hrm.local'],
            [
                'name'      => 'Admin',
                'password'  => 'password',
                'role_id'   => $admin->id,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'member@cyc-hrm.local'],
            [
                'name'      => 'Member',
                'password'  => 'password',
                'role_id'   => $member->id,
                'is_active' => true,
            ]
        );
    }
}

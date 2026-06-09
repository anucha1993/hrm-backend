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
                'attendance.summary.view' => 'ดูสรุปเวลาทำงานรวม',
            ],
            'leave' => [
                'leave.request' => 'ยื่นคำขอลา และดูของตนเอง',
                'leave.approve' => 'อนุมัติใบลา',
                'leave.config' => 'ตั้งค่าประเภทการลา / โควต้า',
            ],
            'tasks' => [
                'tasks.view'   => 'ดูงาน',
                'tasks.manage' => 'จัดการงาน',
            ],
            'payroll' => [
                'payroll.view'           => 'ดูเงินเดือน',
                'payroll.compute'        => 'คำนวณเงินเดือน (HR)',
                'payroll.approve_l1'     => 'อนุมัติขั้นที่ 1 (Manager)',
                'payroll.approve_l2'     => 'อนุมัติขั้นที่ 2 (Owner)',
                'payroll.approve'        => 'อนุมัติเงินเดือน (รวม)',
                'payroll.pay'            => 'บันทึกจ่ายเงินเดือน',
                'payroll.config'         => 'ตั้งค่าระบบเงินเดือน (Profile/Tax/Component)',
                'payroll.ot_manage'      => 'จัดการรอบ OT',
            ],
            'goods_deposits' => [
                'goods_deposits.view'   => 'ดูใบมัดจำของใช้ทั่วไป',
                'goods_deposits.create' => 'เพิ่มใบมัดจำของใช้ทั่วไป',
                'goods_deposits.update' => 'แก้ไข/ตัดยอดใบมัดจำของใช้ทั่วไป',
                'goods_deposits.delete' => 'ลบใบมัดจำของใช้ทั่วไป',
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
        $hr = Role::updateOrCreate(
            ['name' => Role::HR],
            ['display_name' => 'HR', 'description' => 'ฝ่ายบุคคล (คำนวณเงินเดือน)', 'is_system' => true]
        );
        $manager = Role::updateOrCreate(
            ['name' => Role::MANAGER],
            ['display_name' => 'Manager', 'description' => 'ผู้จัดการ (อนุมัติขั้นที่ 1)', 'is_system' => true]
        );
        $owner = Role::updateOrCreate(
            ['name' => Role::OWNER],
            ['display_name' => 'Owner', 'description' => 'เจ้าของ/กรรมการ (อนุมัติขั้นสุดท้าย + จ่าย)', 'is_system' => true]
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
            'leave.request',
            'reports.view',
        ];
        $member->permissions()->sync(
            collect($memberPerms)->map(fn ($n) => $allPermissions[$n]->id)->all()
        );

        // Employee: ลงเวลา + ยื่นลา + ดูสลิปตนเอง
        $employeePerms = [
            'attendance.checkin',
            'leave.request',
            'payroll.view',
        ];
        $employee->permissions()->sync(
            collect($employeePerms)->map(fn ($n) => $allPermissions[$n]->id)->all()
        );

        // HR: จัดการพนักงาน + คำนวณเงินเดือน + OT + ตั้งค่าระบบเงินเดือน
        $hrPerms = [
            'employees.view', 'employees.create', 'employees.update',
            'attendance.view', 'attendance.manage', 'attendance.summary.view',
            'payroll.view', 'payroll.compute', 'payroll.config', 'payroll.ot_manage',
            'leave.request', 'leave.approve', 'leave.config',
            'reports.view',
            'master_data.manage',
            'goods_deposits.view', 'goods_deposits.create', 'goods_deposits.update', 'goods_deposits.delete',
        ];
        $hr->permissions()->sync(
            collect($hrPerms)->filter(fn ($n) => isset($allPermissions[$n]))
                ->map(fn ($n) => $allPermissions[$n]->id)->all()
        );

        // Manager: อนุมัติขั้นที่ 1
        $managerPerms = [
            'employees.view',
            'attendance.view', 'attendance.summary.view',
            'payroll.view', 'payroll.approve_l1',
            'leave.request', 'leave.approve',
            'reports.view',
            'goods_deposits.view',
        ];
        $manager->permissions()->sync(
            collect($managerPerms)->filter(fn ($n) => isset($allPermissions[$n]))
                ->map(fn ($n) => $allPermissions[$n]->id)->all()
        );

        // Owner: อนุมัติขั้นสุดท้าย + บันทึกจ่าย
        $ownerPerms = [
            'employees.view',
            'attendance.view', 'attendance.summary.view',
            'payroll.view', 'payroll.approve_l1', 'payroll.approve_l2', 'payroll.approve', 'payroll.pay',
            'leave.request', 'leave.approve',
            'reports.view',
            'goods_deposits.view',
        ];
        $owner->permissions()->sync(
            collect($ownerPerms)->filter(fn ($n) => isset($allPermissions[$n]))
                ->map(fn ($n) => $allPermissions[$n]->id)->all()
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

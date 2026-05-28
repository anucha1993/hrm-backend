<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Department;
use App\Models\EmploymentType;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['code' => 'HR',    'name' => 'ฝ่ายบุคคล'],
            ['code' => 'ACC',   'name' => 'ฝ่ายบัญชีและการเงิน'],
            ['code' => 'PROD',  'name' => 'ฝ่ายผลิตคอนกรีต'],
            ['code' => 'POUR',  'name' => 'ฝ่ายเทคอนกรีต'],
            ['code' => 'LIFT',  'name' => 'ฝ่ายยก/เครน'],
            ['code' => 'TRANS', 'name' => 'ฝ่ายขนส่ง'],
            ['code' => 'CLEAN', 'name' => 'ฝ่ายทำความสะอาด'],
            ['code' => 'QC',    'name' => 'ฝ่ายควบคุมคุณภาพ'],
        ];
        foreach ($departments as $d) {
            Department::updateOrCreate(['code' => $d['code']], $d + ['is_active' => true]);
        }

        $countries = [
            ['code' => 'TH', 'name' => 'ไทย',    'nationality' => 'ไทย'],
            ['code' => 'LA', 'name' => 'ลาว',    'nationality' => 'ลาว'],
            ['code' => 'MM', 'name' => 'เมียนมา', 'nationality' => 'พม่า'],
            ['code' => 'KH', 'name' => 'กัมพูชา', 'nationality' => 'กัมพูชา'],
        ];
        foreach ($countries as $c) {
            Country::updateOrCreate(['code' => $c['code']], $c + ['is_active' => true]);
        }

        $types = [
            ['code' => 'MONTHLY',  'name' => 'รายเดือน'],
            ['code' => 'DAILY',    'name' => 'รายวัน'],
            ['code' => 'HOURLY',   'name' => 'รายชั่วโมง'],
            ['code' => 'CONTRACT', 'name' => 'สัญญาจ้าง'],
            ['code' => 'INTERN',   'name' => 'ฝึกงาน'],
        ];
        foreach ($types as $t) {
            EmploymentType::updateOrCreate(['code' => $t['code']], $t + ['is_active' => true]);
        }
    }
}

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
            ['code' => 'SALE',  'name' => 'ฝ่ายขาย'],
            ['code' => 'OFF',   'name' => 'ออฟฟิศ (เสมียน)'],
            ['code' => 'DRV',   'name' => 'คนขับรถ'],
            ['code' => 'HLP',   'name' => 'เด็กรถ'],
            ['code' => 'PYF',   'name' => 'แผ่นพื้นยก แพหน้า'],
            ['code' => 'PYB',   'name' => 'แผ่นพื้นยก แพหลัง'],
            ['code' => 'PTF',   'name' => 'แผ่นพื้นเท แพหน้า'],
            ['code' => 'PTB',   'name' => 'แผ่นพื้นเท แพหลัง'],
            ['code' => 'PSY',   'name' => 'อัดแรง-ยก'],
            ['code' => 'PST',   'name' => 'อัดแรง-เท'],
            ['code' => 'SIY',   'name' => 'เสาไอ-ยก'],
            ['code' => 'SIT',   'name' => 'เสาไอ-เท'],
            ['code' => 'PIL',   'name' => 'เสาเข็ม ธรรมดา'],
            ['code' => 'R33',   'name' => 'เสารั้ว 3x3'],
            ['code' => 'R44',   'name' => 'เสารั้ว 4x4'],
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

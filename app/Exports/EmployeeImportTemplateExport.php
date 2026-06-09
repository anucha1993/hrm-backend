<?php

namespace App\Exports;

use App\Models\Country;
use App\Models\Department;
use App\Models\EmploymentType;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class EmployeeImportTemplateExport implements WithMultipleSheets
{
    use Exportable;

    public function sheets(): array
    {
        return [
            new EmployeeImportTemplateSheet(),
            new EmployeeImportHelperSheet(),
        ];
    }
}

/* ===================== Sheet 1: Template ===================== */
class EmployeeImportTemplateSheet implements
    \Maatwebsite\Excel\Concerns\FromArray,
    \Maatwebsite\Excel\Concerns\WithHeadings,
    \Maatwebsite\Excel\Concerns\WithTitle,
    \Maatwebsite\Excel\Concerns\ShouldAutoSize,
    \Maatwebsite\Excel\Concerns\WithStyles
{
    public function title(): string { return 'Template'; }

    public function headings(): array
    {
        return [
            'employee_code', 'title', 'first_name', 'last_name', 'nickname',
            'birth_date', 'gender', 'national_id',
            'phone', 'email', 'address',
            'marital_status', 'religion', 'education_level',
            'country_code', 'department_code', 'employment_type_code',
            'position', 'hire_date', 'resign_date', 'base_salary',
            'bank_name', 'bank_account_no', 'bank_account_name',
            'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
            'status', 'note',
        ];
    }

    public function array(): array
    {
        // Sample row - users delete this and add their own data
        return [
            [
                'EMP100', 'นาย', 'สมชาย', 'ใจดี', 'ชาย',
                '1990-05-15', 'M', '1234567890123',
                '0812345678', 'somchai@example.com', '123 หมู่ 1 ต.ในเมือง อ.เมือง',
                'โสด', 'พุทธ', 'ปริญญาตรี',
                'TH', 'POUR', 'MONTHLY',
                'พนักงานเทคอนกรีต', '2026-06-01', '', '15000',
                'ธนาคารกสิกรไทย', '1234567890', 'นายสมชาย ใจดี',
                'นางสมศรี ใจดี', 'แม่', '0898765432',
                'active', 'ตัวอย่างข้อมูล - ลบแถวนี้ก่อนนำเข้า',
            ],
        ];
    }

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E3A8A'],
                ],
                'alignment' => ['horizontal' => 'center'],
            ],
            2 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FEF3C7'],
                ],
            ],
        ];
    }
}

/* ===================== Sheet 2: Helper ===================== */
class EmployeeImportHelperSheet implements
    \Maatwebsite\Excel\Concerns\FromArray,
    \Maatwebsite\Excel\Concerns\WithHeadings,
    \Maatwebsite\Excel\Concerns\WithTitle,
    \Maatwebsite\Excel\Concerns\ShouldAutoSize,
    \Maatwebsite\Excel\Concerns\WithStyles
{
    public function title(): string { return 'รายการรหัสที่ใช้ได้'; }

    public function headings(): array
    {
        return ['ฟิลด์', 'รหัสที่ใช้ใส่ใน template', 'ชื่อ/คำอธิบาย'];
    }

    public function array(): array
    {
        $rows = [];

        // Title
        foreach (['นาย', 'นางสาว', 'นาง'] as $t) {
            $rows[] = ['title', $t, 'คำนำหน้า'];
        }

        // Gender
        $rows[] = ['gender', 'M', 'ชาย'];
        $rows[] = ['gender', 'F', 'หญิง'];
        $rows[] = ['gender', 'Other', 'อื่น ๆ'];

        // Status
        foreach ([
            'active' => 'ทำงานอยู่',
            'resigned' => 'ลาออก',
            'terminated' => 'เลิกจ้าง',
            'suspended' => 'พักงาน',
        ] as $code => $label) {
            $rows[] = ['status', $code, $label];
        }

        $rows[] = ['', '', ''];

        // Country
        foreach (Country::orderBy('code')->get() as $c) {
            $rows[] = ['country_code', $c->code, $c->name . ' (' . $c->nationality . ')'];
        }

        $rows[] = ['', '', ''];

        // Department
        foreach (Department::where('is_active', true)->orderBy('code')->get() as $d) {
            $rows[] = ['department_code', $d->code, $d->name];
        }

        $rows[] = ['', '', ''];

        // Employment Type
        foreach (EmploymentType::where('is_active', true)->orderBy('code')->get() as $e) {
            $rows[] = ['employment_type_code', $e->code, $e->name];
        }

        return $rows;
    }

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '047857'],
                ],
            ],
        ];
    }
}

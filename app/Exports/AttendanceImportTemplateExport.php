<?php

namespace App\Exports;

use App\Models\Employee;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AttendanceImportTemplateExport implements WithMultipleSheets
{
    use Exportable;

    public function sheets(): array
    {
        return [
            new AttendanceImportTemplateSheet(),
            new AttendanceImportEmployeeSheet(),
        ];
    }
}

/* ===================== Sheet 1: Template ===================== */
class AttendanceImportTemplateSheet implements
    \Maatwebsite\Excel\Concerns\FromArray,
    \Maatwebsite\Excel\Concerns\WithHeadings,
    \Maatwebsite\Excel\Concerns\WithTitle,
    \Maatwebsite\Excel\Concerns\ShouldAutoSize,
    \Maatwebsite\Excel\Concerns\WithEvents,
    \Maatwebsite\Excel\Concerns\WithStyles
{
    public function title(): string { return 'Template'; }

    public function headings(): array
    {
        return [
            'employee_code', 'date', 'check_in', 'check_out', 'note',
        ];
    }

    public function array(): array
    {
        // แถวตัวอย่าง - ผู้ใช้ลบออกทั้งหมดก่อนกรอกข้อมูลจริง
        return [
            ['EMP001', '2026-06-15', '08:00', '17:00', 'ตัวอย่าง: เข้า-ออก ปกติ'],
            ['EMP002', '2026-06-15', '09:20', '17:00', 'ตัวอย่าง: มาสาย (ระบบคำนวณนาทีสายให้เอง)'],
            ['EMP003', '2026-06-15', '08:00', '', 'ตัวอย่าง: มีเฉพาะเวลาเข้า (เว้นช่องออกได้)'],
            ['EMP004', '2026-06-15', '', '17:30', 'ตัวอย่าง: มีเฉพาะเวลาออก (เว้นช่องเข้าได้)'],
            ['EMP005', '2026-06-15', '08:00', '20:00', 'ตัวอย่าง: เลิกดึก (ระบบจัดเป็น OT ให้เอง)'],
            ['EMP006', '2026-06-16', '07:55', '17:05', 'ตัวอย่าง: คนละวัน — กรอกได้หลายวันในไฟล์เดียว'],
        ];
    }

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
    {
        // เน้นทุกแถวตัวอย่าง (แถว 2 ถึง 7) เป็นสีเหลือง ตัวเอียง เพื่อสื่อว่าให้ลบก่อนนำเข้า
        $sampleStyle = [
            'font' => ['italic' => true, 'color' => ['rgb' => '92400E']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEF3C7'],
            ],
        ];

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E3A8A'],
                ],
                'alignment' => ['horizontal' => 'center'],
            ],
            2 => $sampleStyle,
            3 => $sampleStyle,
            4 => $sampleStyle,
            5 => $sampleStyle,
            6 => $sampleStyle,
            7 => $sampleStyle,
        ];
    }

    public function registerEvents(): array
    {
        return [
            \Maatwebsite\Excel\Events\AfterSheet::class => function (\Maatwebsite\Excel\Events\AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // พาเนล "วิธีกรอกฟอร์ม" ด้านขวาตาราง (คอลัมน์ G แถว 1-7)
                // วางในแถวเดียวกับหัวตาราง+แถวตัวอย่าง จึงไม่สร้างแถวข้อมูลส่วนเกินตอนนำเข้า
                $lines = [
                    'วิธีกรอกฟอร์มนี้ — ลบแถวตัวอย่างสีเหลืองทั้งหมดก่อนนำเข้าจริง',
                    '• employee_code — รหัสพนักงาน (ดูรหัสได้จากชีต "รหัสพนักงาน")',
                    '• date — วันที่ รูปแบบ YYYY-MM-DD เช่น 2026-06-15',
                    '• check_in / check_out — เวลา รูปแบบ HH:MM เช่น 08:00 (เว้นว่างได้)',
                    '• note — หมายเหตุ (ไม่บังคับ)',
                    '• 1 แถว = 1 วัน ของพนักงาน 1 คน (ใส่ได้หลายวัน/หลายคน)',
                    '• ระบบคำนวณ สาย / ออกก่อน / OT จากกะของพนักงานอัตโนมัติ',
                ];
                $row = 1;
                foreach ($lines as $text) {
                    $sheet->setCellValue('G' . $row, $text);
                    $row++;
                }
                $last = count($lines);

                $sheet->getColumnDimension('G')->setAutoSize(false)->setWidth(64);

                // กรอบ + พื้นหลังพาเนล
                $sheet->getStyle('G1:G' . $last)->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'EFF6FF'],
                    ],
                    'alignment' => [
                        'horizontal' => 'left',
                        'vertical' => 'center',
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'outline' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => 'BFDBFE'],
                        ],
                    ],
                ]);

                // หัวข้อพาเนล (ตัวหนา สีน้ำเงินเข้ม)
                $sheet->getStyle('G1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'B91C1C']],
                ]);
            },
        ];
    }
}

/* ===================== Sheet 2: รายชื่อพนักงาน ===================== */
class AttendanceImportEmployeeSheet implements
    \Maatwebsite\Excel\Concerns\FromArray,
    \Maatwebsite\Excel\Concerns\WithHeadings,
    \Maatwebsite\Excel\Concerns\WithTitle,
    \Maatwebsite\Excel\Concerns\ShouldAutoSize,
    \Maatwebsite\Excel\Concerns\WithStyles
{
    public function title(): string { return 'รหัสพนักงาน'; }

    public function headings(): array
    {
        return ['employee_code', 'ชื่อ-นามสกุล', 'แผนก'];
    }

    public function array(): array
    {
        $rows = [];

        // คำอธิบายรูปแบบข้อมูล
        $rows[] = ['รูปแบบ date', 'YYYY-MM-DD เช่น 2026-06-15', ''];
        $rows[] = ['รูปแบบ check_in / check_out', 'HH:MM เช่น 08:00 หรือ 17:30', 'เว้นว่างได้ถ้าไม่มีเวลานั้น'];
        $rows[] = ['หมายเหตุ', 'ระบบจะคำนวณ "สาย/ออกก่อน/OT" จากกะของพนักงานอัตโนมัติ', ''];
        $rows[] = ['', '', ''];

        foreach (
            Employee::with('department')
                ->where('status', 'active')
                ->orderBy('employee_code')
                ->get() as $e
        ) {
            $rows[] = [
                $e->employee_code,
                trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')),
                $e->department?->name ?? '-',
            ];
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

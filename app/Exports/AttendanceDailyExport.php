<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AttendanceDailyExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(
        protected array $employee,
        protected string $month,
        protected array $days
    ) {}

    public function collection(): Collection
    {
        return collect($this->days);
    }

    public function title(): string
    {
        return 'รายวัน ' . $this->month;
    }

    public function headings(): array
    {
        $name = trim(($this->employee['first_name'] ?? '') . ' ' . ($this->employee['last_name'] ?? ''));
        return [
            ['รายวันการมาทำงาน — ' . ($this->employee['employee_code'] ?? '') . ' ' . $name . ' (เดือน ' . $this->month . ')'],
            [],
            ['วันที่', 'วัน', 'สถานะ', 'เข้างาน', 'ออกงาน', 'สายกี่นาที', 'ประเภทการลา', 'ครึ่งวัน'],
        ];
    }

    public function map($d): array
    {
        $dows = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัส', 'ศุกร์', 'เสาร์'];
        $statusMap = [
            'present' => 'มา',
            'late' => 'สาย',
            'absent' => 'ขาด',
            'leave' => 'ลา',
            'weekend' => 'หยุดประจำสัปดาห์',
        ];
        return [
            $d['date'] ?? '',
            $dows[$d['day_of_week'] ?? 0] ?? '',
            $statusMap[$d['status'] ?? ''] ?? ($d['status'] ?? ''),
            $d['check_in'] ?? '-',
            $d['check_out'] ?? '-',
            $d['late_minutes'] ?? 0,
            $d['leave']['type'] ?? '-',
            isset($d['leave']) && $d['leave'] && ! empty($d['leave']['is_half_day']) ? 'ครึ่งวัน' : '-',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastCol = $sheet->getHighestColumn();

                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                if ($lastRow >= 4) {
                    $sheet->getStyle("A4:{$lastCol}{$lastRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                    ]);
                }
            },
        ];
    }
}

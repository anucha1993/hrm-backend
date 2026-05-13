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

class AttendanceSummaryExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(
        protected array $rows,
        protected array $period,
        protected array $totals
    ) {}

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function title(): string
    {
        return 'สรุปการมาทำงาน';
    }

    public function headings(): array
    {
        return [
            ['สรุปการมาทำงาน ' . ($this->period['start'] ?? '') . ' ถึง ' . ($this->period['end'] ?? '') . ' (รวม ' . ($this->period['total_days'] ?? 0) . ' วัน)'],
            [],
            [
                'รหัสพนักงาน',
                'ชื่อ',
                'นามสกุล',
                'แผนก',
                'วันทำงานทั้งหมด',
                'มา',
                'ขาด',
                'ลา (วัน)',
                'ลามีค่าจ้าง',
                'ลาไม่มีค่าจ้าง',
                'จำนวนวันที่สาย',
                'นาทีสายรวม',
                'OT (ชม.)',
            ],
        ];
    }

    public function map($row): array
    {
        return [
            $row['employee']['employee_code'] ?? '',
            $row['employee']['first_name'] ?? '',
            $row['employee']['last_name'] ?? '',
            $row['employee']['department']['name'] ?? '-',
            $row['total_days'] ?? 0,
            $row['present_days'] ?? 0,
            $row['absent_days'] ?? 0,
            $row['leave_days'] ?? 0,
            $row['paid_leave_days'] ?? 0,
            $row['unpaid_leave_days'] ?? 0,
            $row['late_count'] ?? 0,
            $row['late_minutes'] ?? 0,
            $row['ot_hours'] ?? 0,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastCol = $sheet->getHighestColumn();

                // Title merge
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle("A1")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Header row (row 3)
                $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);

                // Data borders
                if ($lastRow >= 4) {
                    $sheet->getStyle("A4:{$lastCol}{$lastRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                    ]);
                }

                // Totals row
                $totalRow = $lastRow + 1;
                $sheet->setCellValue("A{$totalRow}", 'รวม');
                $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
                $sheet->setCellValue("F{$totalRow}", $this->totals['present_days'] ?? 0);
                $sheet->setCellValue("G{$totalRow}", $this->totals['absent_days'] ?? 0);
                $sheet->setCellValue("H{$totalRow}", $this->totals['leave_days'] ?? 0);
                $sheet->setCellValue("K{$totalRow}", $this->totals['late_count'] ?? 0);
                $sheet->setCellValue("M{$totalRow}", $this->totals['ot_hours'] ?? 0);
                $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                ]);
            },
        ];
    }
}

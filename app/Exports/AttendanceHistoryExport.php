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

class AttendanceHistoryExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(protected Collection $records) {}

    public function collection(): Collection
    {
        return $this->records;
    }

    public function title(): string
    {
        return 'ประวัติลงเวลา';
    }

    public function headings(): array
    {
        return [
            ['ประวัติการลงเวลาทำงาน (Export: ' . now()->format('Y-m-d H:i') . ')'],
            [],
            [
                'วันที่',
                'เวลา',
                'รหัสพนักงาน',
                'ชื่อ-นามสกุล',
                'ประเภท',
                'สถานะ',
                'สายกี่นาที',
                'สถานที่',
                'นอกพื้นที่?',
                'แหล่งที่มา',
                'แก้ไข?',
                'หมายเหตุ',
            ],
        ];
    }

    public function map($att): array
    {
        return [
            $att->checked_at?->format('Y-m-d'),
            $att->checked_at?->format('H:i:s'),
            $att->employee?->employee_code,
            trim(($att->employee?->first_name ?? '') . ' ' . ($att->employee?->last_name ?? '')),
            $att->type === 'check_in' ? 'เข้างาน' : ($att->type === 'check_out' ? 'ออกงาน' : $att->type),
            $att->status === 'late' ? 'สาย' : ($att->status === 'normal' ? 'ปกติ' : $att->status),
            (int) ($att->late_minutes ?? 0),
            $att->officeLocation?->name ?? '-',
            $att->outside_geofence ? 'ใช่' : 'ไม่',
            $att->source ?? 'self',
            $att->is_edited ? 'แก้ไขแล้ว' : '-',
            $att->note ?? '',
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

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

class OtSessionsExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    /** @var \Illuminate\Support\Collection<array> */
    protected Collection $rows;

    public function __construct(Collection $sessions)
    {
        $rows = collect();
        foreach ($sessions as $s) {
            if ($s->employees->isEmpty()) {
                $rows->push([
                    'session' => $s,
                    'emp' => null,
                ]);
                continue;
            }
            foreach ($s->employees as $emp) {
                $rows->push([
                    'session' => $s,
                    'emp' => $emp,
                ]);
            }
        }
        $this->rows = $rows;
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'OT Sessions';
    }

    public function headings(): array
    {
        return [
            ['รายการ OT (Export: ' . now()->format('Y-m-d H:i') . ')'],
            [],
            [
                'วันที่',
                'เริ่ม',
                'สิ้นสุด',
                'ประเภท OT',
                'โหมดอัตรา',
                'อัตรา (บาท/ชม.)',
                'ตัวคูณ',
                'สถานะ',
                'รหัสพนักงาน',
                'ชื่อ-นามสกุล',
                'ชั่วโมง',
                'รายละเอียด',
            ],
        ];
    }

    public function map($row): array
    {
        $s = $row['session'];
        $e = $row['emp'];
        $typeMap = ['normal' => 'ปกติ', 'holiday' => 'วันหยุด', 'holiday_overtime' => 'OT วันหยุด'];
        $statusMap = ['draft' => 'ร่าง', 'open' => 'เปิด', 'closed' => 'ปิด'];
        return [
            $s->ot_date?->format('Y-m-d'),
            $s->start_time,
            $s->end_time,
            $typeMap[$s->ot_type] ?? $s->ot_type,
            $s->rate_mode === 'multiplier' ? 'ตัวคูณ' : 'เรท',
            $s->hourly_amount,
            $s->multiplier,
            $statusMap[$s->status] ?? $s->status,
            $e?->employee?->employee_code ?? '-',
            $e ? trim(($e->employee?->first_name ?? '') . ' ' . ($e->employee?->last_name ?? '')) : '-',
            $e?->hours ?? 0,
            $s->description ?? '',
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

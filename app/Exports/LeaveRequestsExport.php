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

class LeaveRequestsExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(protected Collection $requests) {}

    public function collection(): Collection
    {
        return $this->requests;
    }

    public function title(): string
    {
        return 'ใบลา';
    }

    public function headings(): array
    {
        return [
            ['รายการใบลา (Export: ' . now()->format('Y-m-d H:i') . ')'],
            [],
            [
                'เลขที่ใบลา',
                'รหัสพนักงาน',
                'ชื่อ-นามสกุล',
                'ประเภทการลา',
                'วันที่เริ่ม',
                'วันที่สิ้นสุด',
                'จำนวนวัน',
                'ครึ่งวัน',
                'เหตุผล',
                'สถานะ',
                'ผู้อนุมัติ',
                'วันที่อนุมัติ',
                'หมายเหตุผู้อนุมัติ',
            ],
        ];
    }

    public function map($r): array
    {
        $statusMap = [
            'draft' => 'ร่าง',
            'pending' => 'รออนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ไม่อนุมัติ',
            'cancelled' => 'ยกเลิก',
        ];
        return [
            $r->request_no,
            $r->employee?->employee_code,
            trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->last_name ?? '')),
            $r->leaveType?->name,
            $r->start_date?->format('Y-m-d'),
            $r->end_date?->format('Y-m-d'),
            (float) $r->total_days,
            $r->is_half_day ? ($r->half_day_period === 'morning' ? 'ครึ่งเช้า' : 'ครึ่งบ่าย') : '-',
            $r->reason ?? '',
            $statusMap[$r->status] ?? $r->status,
            $r->reviewer?->name ?? '-',
            $r->reviewed_at?->format('Y-m-d H:i') ?? '-',
            $r->review_note ?? '',
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

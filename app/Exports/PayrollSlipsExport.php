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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PayrollSlipsExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(protected Collection $slips) {}

    public function collection(): Collection
    {
        return $this->slips;
    }

    public function title(): string
    {
        return 'สลิปเงินเดือน';
    }

    public function headings(): array
    {
        return [
            ['สลิปเงินเดือน (Export: ' . now()->format('Y-m-d H:i') . ')'],
            [],
            [
                'เลขสลิป',
                'รอบจ่าย',
                'รหัสพนักงาน',
                'ชื่อ-นามสกุล',
                'เงินเดือนพื้นฐาน',
                'วันทำงาน',
                'มา',
                'ขาด',
                'ลา',
                'นาทีสาย',
                'OT (ชม.)',
                'เงินเดือนคำนวณ',
                'OT จ่าย',
                'เบี้ยเลี้ยง',
                'โบนัส',
                'รายรับรวม',
                'หักสาย',
                'หักขาด',
                'ประกันสังคม',
                'ภาษี',
                'หักอื่นๆ',
                'หักรวม',
                'เงินสุทธิ',
                'สถานะ',
            ],
        ];
    }

    public function map($slip): array
    {
        $statusMap = [
            'draft' => 'ร่าง', 'pending_l1' => 'รออนุมัติ L1', 'approved_l1' => 'อนุมัติ L1',
            'pending_l2' => 'รออนุมัติ L2', 'approved_l2' => 'อนุมัติ L2',
            'approved' => 'อนุมัติ', 'paid' => 'จ่ายแล้ว', 'rejected' => 'ปฏิเสธ', 'cancelled' => 'ยกเลิก',
        ];
        return [
            $slip->slip_no,
            $slip->period?->name ?? $slip->period?->code,
            $slip->employee?->employee_code,
            trim(($slip->employee?->first_name ?? '') . ' ' . ($slip->employee?->last_name ?? '')),
            (float) $slip->base_salary,
            (int) $slip->working_days,
            (int) $slip->present_days,
            (int) $slip->absent_days,
            (float) $slip->leave_days,
            (int) $slip->late_minutes_total,
            (float) $slip->ot_hours_total,
            (float) $slip->base_pay,
            (float) $slip->ot_pay,
            (float) $slip->allowances_total,
            (float) $slip->bonus_total,
            (float) $slip->gross_pay,
            (float) $slip->late_deduction,
            (float) $slip->absent_deduction,
            (float) $slip->ssf_employee,
            (float) $slip->tax,
            (float) $slip->other_deductions_total,
            (float) $slip->deductions_total,
            (float) $slip->net_pay,
            $statusMap[$slip->status] ?? $slip->status,
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
                    // Number format for money columns (E, L..W)
                    foreach (['E', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W'] as $col) {
                        $sheet->getStyle("{$col}4:{$col}{$lastRow}")
                            ->getNumberFormat()
                            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    }
                }
            },
        ];
    }
}

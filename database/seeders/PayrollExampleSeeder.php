<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\CompensationProfile;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollApproval;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipItem;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * สร้างตัวอย่างการคำนวณเงินเดือนให้เห็นภาพ "หักจากกฎ" ชัดเจน
 * รัน: php artisan db:seed --class=PayrollExampleSeeder
 *
 * Scenarios (รอบ พ.ค. 2026):
 *  1) มาตรง + ไม่ขาด                → ได้เบี้ยขยัน +1,000
 *  2) สาย 3 ครั้ง                    → tier1 หัก 100
 *  3) สาย 5 ครั้ง                    → tier2 หัก 300
 *  4) ขาด 1 วัน                      → หัก 1,500
 *  5) ขาด 2 วัน + สาย 7 ครั้ง         → หัก 3,000 + tier3 600
 */
class PayrollExampleSeeder extends Seeder
{
    public function run(): void
    {
        $start = Carbon::create(2026, 5, 1);
        $end   = Carbon::create(2026, 5, 31);

        $period = PayrollPeriod::updateOrCreate(
            ['code' => 'PER-2026-05'],
            [
                'name'       => 'งวดพฤษภาคม 2026',
                'start_date' => $start,
                'end_date'   => $end,
                'pay_date'   => Carbon::create(2026, 5, 31),
                'status'     => 'draft',
                'note'       => 'งวดทดลองสำหรับสาธิตการหักเงินตามกฎ',
            ],
        );

        $profile = CompensationProfile::where('is_default', true)->first()
            ?? CompensationProfile::firstOrFail();

        // เลือกพนักงาน 5 คนแรก
        $employees = Employee::orderBy('id')->limit(5)->get();
        if ($employees->count() < 5) {
            $this->command->warn('ต้องการพนักงานอย่างน้อย 5 คน — ข้ามการสร้างตัวอย่าง');
            return;
        }

        $scenarios = [
            ['salary' => 25000, 'late_days' => 0, 'absent_days' => 0, 'desc' => 'มาตรงเวลา — รับเบี้ยขยัน'],
            ['salary' => 22000, 'late_days' => 3, 'absent_days' => 0, 'desc' => 'สาย 3 ครั้ง'],
            ['salary' => 28000, 'late_days' => 5, 'absent_days' => 0, 'desc' => 'สาย 5 ครั้ง'],
            ['salary' => 20000, 'late_days' => 0, 'absent_days' => 1, 'desc' => 'ขาด 1 วัน'],
            ['salary' => 30000, 'late_days' => 7, 'absent_days' => 2, 'desc' => 'ขาด 2 วัน + สาย 7 ครั้ง'],
        ];

        $service = app(PayrollCalculationService::class);
        $allDays = $this->daysBetween($start, $end);
        $weekdays = $this->weekdays($start, $end);

        foreach ($employees as $idx => $emp) {
            $sc = $scenarios[$idx];
            $this->command->info("• {$emp->employee_code} ({$emp->first_name_th} {$emp->last_name_th}) — {$sc['desc']}");

            // 1) ลบข้อมูลเดิมในงวด เพื่อเริ่มต้นใหม่
            Attendance::where('employee_id', $emp->id)
                ->whereBetween('checked_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->delete();
            $oldSlip = PayrollSlip::where('payroll_period_id', $period->id)
                ->where('employee_id', $emp->id)
                ->first();
            if ($oldSlip) {
                PayrollSlipItem::where('payroll_slip_id', $oldSlip->id)->delete();
                PayrollApproval::where('payroll_slip_id', $oldSlip->id)->delete();
                $oldSlip->delete();
            }

            // 2) ตั้ง EmployeeCompensation
            EmployeeCompensation::updateOrCreate(
                ['employee_id' => $emp->id, 'is_active' => true],
                [
                    'compensation_profile_id' => $profile->id,
                    'base_salary'             => $sc['salary'],
                    'effective_from'          => $start,
                    'effective_to'            => null,
                ],
            );

            // 3) สร้าง attendance ตาม scenario
            // หมายเหตุ: ระบบนับ absent จากวันปฏิทินทั้งหมด (รวมเสาร์-อาทิตย์)
            // เพื่อความถูกต้องของตัวอย่าง — ตอกบัตรครอบคลุมทุกวัน เว้นแต่ที่ตั้งใจให้ขาด
            $absentDates = array_slice($weekdays, 0, $sc['absent_days']);     // ขาดวันแรก ๆ
            $lateDates   = array_slice($weekdays, $sc['absent_days'], $sc['late_days']);
            foreach ($allDays as $day) {
                if (in_array($day, $absentDates, true)) {
                    continue; // ไม่มา = ขาด
                }
                $isLate  = in_array($day, $lateDates, true);
                $checkIn = Carbon::parse($day . ' 08:' . ($isLate ? '02' : '00') . ':00');
                $checkOut = Carbon::parse($day . ' 17:30:00');

                Attendance::create([
                    'employee_id'   => $emp->id,
                    'type'          => 'check_in',
                    'checked_at'    => $checkIn,
                    'status'        => $isLate ? 'late' : 'normal',
                    'late_minutes'  => $isLate ? 2 : 0,
                    'source'        => 'manual',
                ]);
                Attendance::create([
                    'employee_id'  => $emp->id,
                    'type'         => 'check_out',
                    'checked_at'   => $checkOut,
                    'status'       => 'normal',
                    'late_minutes' => 0,
                    'source'       => 'manual',
                ]);
            }

            // 4) คำนวณสลิป
            try {
                $slip = $service->computeForEmployee($period, $emp, null);
                $this->command->line(sprintf(
                    '  → base %s | bonus %s | deductions %s | net %s',
                    number_format((float) $slip->base_salary, 2),
                    number_format((float) $slip->bonus_total, 2),
                    number_format((float) $slip->deductions_total, 2),
                    number_format((float) $slip->net_pay, 2),
                ));
            } catch (\Throwable $e) {
                $this->command->error('  ✗ ' . $e->getMessage());
            }
        }

        $this->command->info('เสร็จสิ้น — เปิดดูสลิปได้ที่หน้ารายการเงินเดือน หรือสอบถาม API /api/payroll-slips');
    }

    /** วันทำงาน จ-ศ ในช่วง */
    protected function weekdays(Carbon $start, Carbon $end): array
    {
        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! $d->isWeekend()) {
                $days[] = $d->toDateString();
            }
        }
        return $days;
    }

    /** ทุกวันในช่วง (รวมเสาร์-อาทิตย์) */
    protected function daysBetween(Carbon $start, Carbon $end): array
    {
        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days[] = $d->toDateString();
        }
        return $days;
    }
}

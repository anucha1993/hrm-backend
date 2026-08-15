<?php

namespace App\Services\Payroll;

use App\Models\Attendance;
use App\Models\CompensationProfile;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeComponent;
use App\Models\EmployeeTaxSetting;
use App\Models\OtSession;
use App\Models\OtSessionEmployee;
use App\Models\PayrollApproval;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipItem;
use App\Models\TaxBracket;
use App\Models\TaxProfile;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollCalculationService
{
    /**
     * คำนวณเงินเดือนของพนักงาน 1 คนสำหรับ 1 งวด
     */
    public function computeForEmployee(PayrollPeriod $period, Employee $employee, ?int $userId = null): PayrollSlip
    {
        return DB::transaction(function () use ($period, $employee, $userId) {
            $log = [];

            // 1. โปรไฟล์ค่าจ้าง
            $employeeComp = $this->resolveActiveCompensation($employee, $period);
            if (! $employeeComp) {
                throw new RuntimeException("ไม่พบโปรไฟล์ค่าจ้างของพนักงาน {$employee->employee_code}");
            }
            /** @var CompensationProfile $profile */
            $profile = $employeeComp->profile;
            $baseSalary = (float) $employeeComp->base_salary;
            $log['profile'] = $profile->only(['id', 'name', 'pay_frequency']);
            $log['base_salary'] = $baseSalary;

            // 2. คำนวณ rate ต่อ ชม. / ต่อวัน
            // ใช้จำนวนวันจริงของงวด (start_date..end_date) แทนค่าคงที่ใน profile
            // เพราะงวดจ่ายเงินของบริษัทนี้เป็นแบบ 2 ครั้ง/เดือน (ประมาณ 15-16 วัน/งวด)
            // ไม่ใช่เดือนเต็ม (26 วัน) — ใช้ช่วงวันที่ของงวดที่มีอยู่แล้วแทนการฮาร์ดโค้ด
            $workingDays = max(1, (int) $period->start_date->diffInDays($period->end_date) + 1);
            $workingHours = max(1, (int) $profile->working_hours_per_day);
            $dailyRate = round($baseSalary / $workingDays, 2);
            $hourlyRate = $employeeComp->hourly_rate_override
                ? (float) $employeeComp->hourly_rate_override
                : round($dailyRate / $workingHours, 2);

            // 3. ดึงข้อมูลลงเวลา
            $att = $this->summarizeAttendance($employee, $period);
            $log['attendance'] = $att;

            // 4. ดึง OT จาก ot_session_employees ในช่วงนี้
            $otAgg = $this->summarizeOt($employee, $period, $hourlyRate);
            $log['ot'] = $otAgg;

            // 5. ลบสลิปเก่าของงวด-พนักงานนี้ (ถ้าคำนวณซ้ำ)
            $existing = PayrollSlip::where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->whereNotIn('status', ['paid', 'approved'])
                ->first();
            if ($existing) {
                $existing->items()->delete();
                $existing->delete();
            } else {
                $locked = PayrollSlip::where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', ['paid', 'approved'])
                    ->exists();
                if ($locked) {
                    throw new RuntimeException('สลิปงวดนี้ถูกอนุมัติ/จ่ายแล้ว ไม่สามารถคำนวณซ้ำได้');
                }
            }

            // 6. สร้างสลิป (snapshot)
            $slip = PayrollSlip::create([
                'slip_no' => $this->generateSlipNo($period, $employee),
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'compensation_profile_id' => $profile->id,
                'profile_snapshot' => $profile->toArray(),
                'base_salary' => $baseSalary,
                'hourly_rate' => $hourlyRate,
                'daily_rate' => $dailyRate,
                'working_days' => $workingDays,
                'present_days' => $att['present_days'],
                'absent_days' => $att['absent_days'],
                'leave_days' => $att['leave_days'],
                'late_count' => $att['late_count'],
                'late_minutes_total' => $att['late_minutes_total'],
                'ot_hours_total' => $otAgg['hours'],
                'status' => 'draft',
            ]);

            $items = [];
            $order = 0;

            // 7. base_pay (เต็มเดือนหรือคิดตามวันที่มาทำงาน?)
            // นโยบาย: ใช้ base_salary เต็ม แล้วหัก absent/late แยกผ่านกฎและ profile
            $basePay = $baseSalary;
            $items[] = $this->makeItem($slip, 'earning', 'base', 'BASE', 'เงินเดือน', $basePay, $order++, taxable: true, ssf: true);

            // 8. OT Pay
            $otPay = (float) $otAgg['amount'];
            if ($otPay > 0) {
                $items[] = $this->makeItem(
                    $slip, 'earning', 'ot', 'OT', "ค่าล่วงเวลา ({$otAgg['hours']} ชม.)",
                    $otPay, $order++, taxable: true, ssf: false,
                    quantity: $otAgg['hours'], rate: $hourlyRate
                );
            }

            // 9. employee_components (allowance / deduction รายคน)
            $allowances = 0;
            $componentDeductions = 0;
            $componentAllowanceDetails = [];
            foreach ($this->employeeComponents($employee, $period) as $ec) {
                $comp = $ec->component;
                if (! $comp) {
                    continue;
                }
                if ($comp->kind === 'allowance') {
                    $allowances += (float) $ec->amount;
                    $items[] = $this->makeItem(
                        $slip, 'earning', 'component', $comp->code, $comp->name,
                        (float) $ec->amount, $order++,
                        taxable: (bool) $comp->taxable, ssf: (bool) $comp->affects_ssf,
                        referenceId: $ec->id, referenceType: EmployeeComponent::class,
                    );
                    $componentAllowanceDetails[] = ['code' => $comp->code, 'amount' => (float) $ec->amount];
                } else {
                    $componentDeductions += (float) $ec->amount;
                    $items[] = $this->makeItem(
                        $slip, 'deduction', 'component', $comp->code, $comp->name,
                        (float) $ec->amount, $order++,
                        referenceId: $ec->id, referenceType: EmployeeComponent::class,
                    );
                }
            }
            $log['components'] = ['allowances' => $allowances, 'deductions' => $componentDeductions];

            // 10. apply rules ของโปรไฟล์
            $bonusTotal = 0;
            $ruleDeductions = 0;
            $ruleLog = [];
            foreach ($profile->rules()->where('is_active', true)->get() as $rule) {
                $metric = $this->evaluateMetric($rule->trigger, $att, $otAgg);
                $matches = $rule->matches((float) $metric);
                $ruleLog[] = ['rule' => $rule->name, 'trigger' => $rule->trigger, 'metric' => $metric, 'matched' => $matches];
                if (! $matches) {
                    continue;
                }
                $amount = $rule->amount_type === 'percent_of_base'
                    ? round($baseSalary * ((float) $rule->amount) / 100, 2)
                    : (float) $rule->amount;

                if ($rule->action === 'add_bonus' || $rule->action === 'add_allowance') {
                    if ($rule->action === 'add_bonus') {
                        $bonusTotal += $amount;
                    } else {
                        $allowances += $amount;
                    }
                    $items[] = $this->makeItem(
                        $slip, 'earning', 'rule', null, $rule->name, $amount, $order++,
                        taxable: (bool) $rule->taxable, ssf: (bool) $rule->affects_ssf,
                        referenceId: $rule->id, referenceType: \App\Models\ProfileRule::class,
                        formula: "trigger={$rule->trigger}, metric={$metric}",
                    );
                } else { // add_deduction
                    $ruleDeductions += $amount;
                    $items[] = $this->makeItem(
                        $slip, 'deduction', 'rule', null, $rule->name, $amount, $order++,
                        referenceId: $rule->id, referenceType: \App\Models\ProfileRule::class,
                        formula: "trigger={$rule->trigger}, metric={$metric}",
                    );
                }
            }
            $log['rules'] = $ruleLog;

            // 10.5 apply กฎ payroll_rules (ระบบใหม่ — admin ตั้งเองในหน้า /rules)
            $extra = [
                'rating_avg' => $this->avgTaskRating($employee, $period),
            ];
            $engine = app(RuleEngineService::class);
            $engineResult = $engine->evaluate($employee, $period, $baseSalary, $att, $otAgg, $extra);
            $persisted = $engine->persistItems($slip, $engineResult['items'], $order);
            $bonusTotal += $persisted['bonuses'];
            $ruleDeductions += $persisted['deductions'];
            $log['payroll_rules'] = $engineResult['log'];

            // 11. หักสายตามวิธีของโปรไฟล์
            $lateDeduction = $this->computeLateDeduction($profile, $att, $hourlyRate, $dailyRate);
            if ($lateDeduction > 0) {
                $items[] = $this->makeItem(
                    $slip, 'deduction', 'attendance', 'LATE', 'หักมาสาย',
                    $lateDeduction, $order++,
                    formula: "method={$profile->late_deduction_method}, rate={$profile->late_deduction_rate}",
                );
            }

            // 12. หักขาดงาน
            $absentDeduction = $this->computeAbsentDeduction($profile, $att, $dailyRate);
            if ($absentDeduction > 0) {
                $items[] = $this->makeItem(
                    $slip, 'deduction', 'attendance', 'ABSENT', 'หักขาดงาน',
                    $absentDeduction, $order++,
                    formula: "method={$profile->absent_deduction_method}, days={$att['absent_days']}",
                );
            }

            // 13. คำนวณ gross
            $grossPay = round($basePay + $otPay + $allowances + $bonusTotal, 2);

            // 14. SSF (ฝั่งลูกจ้าง 5% บน fixed band 1650-15000 ตามค่าใน profile)
            [$ssfEmp, $ssfEr] = $this->computeSsf($profile, $basePay + $allowances /* taxable+ssfable items */);
            if ($ssfEmp > 0) {
                $items[] = $this->makeItem(
                    $slip, 'ssf', 'tax_calc', 'SSF', 'ประกันสังคม',
                    $ssfEmp, $order++,
                    formula: "rate={$profile->ssf_rate}%, base capped " . number_format($profile->ssf_max_base),
                );
            }

            // 15. ภาษี
            $taxSnapshotMeta = [];
            $tax = $this->computeTax($employee, $grossPay, $ssfEmp, $period, $taxSnapshotMeta);
            if ($tax > 0) {
                $items[] = $this->makeItem(
                    $slip, 'tax', 'tax_calc', 'TAX', 'ภาษีหัก ณ ที่จ่าย',
                    $tax, $order++, formula: $taxSnapshotMeta['formula'] ?? null,
                );
            }
            $log['tax'] = $taxSnapshotMeta;

            // 16. รวมยอดหัก / net
            $deductionsTotal = round($componentDeductions + $ruleDeductions + $lateDeduction + $absentDeduction, 2);

            // 16.5 apply global caps (max_deduction_percent / min_net_salary)
            $caps = app(RuleEngineService::class)->applyGlobalCaps($baseSalary, $deductionsTotal);
            if ($caps['capped']) {
                $delta = round($deductionsTotal - $caps['adjusted_deductions'], 2);
                if ($delta > 0) {
                    $items[] = $this->makeItem(
                        $slip, 'earning', 'manual', 'CAP-ADJ', 'ปรับ cap หักเงิน: ' . $caps['reason'],
                        $delta, $order++,
                    );
                }
                $deductionsTotal = $caps['adjusted_deductions'];
                $log['cap'] = $caps;
            }

            $netPay = round($grossPay - $ssfEmp - $tax - $deductionsTotal, 2);

            // 17. update สลิป
            $slip->update([
                'tax_profile_id' => $taxSnapshotMeta['tax_profile_id'] ?? null,
                'tax_snapshot' => $taxSnapshotMeta,
                'base_pay' => $basePay,
                'ot_pay' => $otPay,
                'allowances_total' => $allowances,
                'bonus_total' => $bonusTotal,
                'gross_pay' => $grossPay,
                'late_deduction' => $lateDeduction,
                'absent_deduction' => $absentDeduction,
                'other_deductions_total' => $componentDeductions + $ruleDeductions,
                'ssf_employee' => $ssfEmp,
                'ssf_employer' => $ssfEr,
                'tax' => $tax,
                'deductions_total' => $deductionsTotal,
                'net_pay' => $netPay,
                'status' => 'computed',
                'calculation_log' => $log,
            ]);

            // 18. ผูก ot_session_employees ↔ slip
            OtSessionEmployee::whereHas('session', function ($q) use ($period) {
                $q->whereBetween('ot_date', [$period->start_date, $period->end_date]);
            })
                ->where('employee_id', $employee->id)
                ->whereNull('payroll_slip_id')
                ->update(['payroll_slip_id' => $slip->id]);

            // 19. log การกระทำ
            PayrollApproval::create([
                'payroll_slip_id' => $slip->id,
                'payroll_period_id' => $period->id,
                'action' => 'compute',
                'from_status' => 'draft',
                'to_status' => 'computed',
                'user_id' => $userId,
            ]);

            return $slip->fresh(['items', 'employee']);
        });
    }

    /* ---------------- helpers ---------------- */

    protected function resolveActiveCompensation(Employee $employee, PayrollPeriod $period): ?EmployeeCompensation
    {
        return EmployeeCompensation::with('profile.rules')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $period->end_date)
            ->where(function ($q) use ($period) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $period->start_date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    protected function summarizeAttendance(Employee $employee, PayrollPeriod $period): array
    {
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('checked_at', [$period->start_date->startOfDay(), $period->end_date->endOfDay()])
            ->get();

        $byDay = $rows->groupBy(fn ($r) => $r->checked_at->toDateString());
        $presentDays = $byDay->count();
        $totalDays = CarbonPeriod::create($period->start_date, $period->end_date)->count();

        // รวมวันลาจากระบบลา (อนุมัติแล้วเท่านั้น)
        $leaveSummary = app(\App\Services\Leave\LeaveService::class)
            ->summarizeForPeriod($employee->id, $period->start_date->toDateString(), $period->end_date->toDateString());
        $leaveDays = (float) $leaveSummary['total_days'];
        $unpaidLeaveDays = (float) $leaveSummary['unpaid_days'];

        // ขาดจริง = วันที่ไม่มา และไม่ได้ลา
        $absentDays = max(0, $totalDays - $presentDays - $leaveDays);
        $lateRows = $rows->filter(fn ($r) => ($r->late_minutes ?? 0) > 0 || $r->status === 'late');
        $lateCount = $lateRows->groupBy(fn ($r) => $r->checked_at->toDateString())->count();
        $lateMinutesTotal = (int) $rows->sum('late_minutes');

        // วันหยุดบริษัทในรอบที่พนักงานไม่มาลงเวลาเลย (ใช้เป็นเหตุ "เสียสิทธิ์" เบี้ยขยันได้ ถ้าตั้งค่าไว้)
        $schedule = app(\App\Services\WorkScheduleService::class);
        $profileId = $schedule->resolveProfile($employee)?->id;
        $holidayDates = collect($schedule->holidaysBetween($profileId, $period->start_date, $period->end_date))
            ->pluck('date');
        $holidayAbsentCount = $holidayDates->filter(fn ($d) => ! $byDay->has($d))->count();

        return [
            'present_days' => $presentDays,
            'absent_days' => $absentDays + $unpaidLeaveDays, // unpaid leave ถือเสมือนขาดสำหรับหักเงิน
            'leave_days' => $leaveDays,
            'paid_leave_days' => (float) $leaveSummary['paid_days'],
            'unpaid_leave_days' => $unpaidLeaveDays,
            'leave_breakdown' => $leaveSummary['by_type'],
            'late_count' => $lateCount,
            'late_minutes_total' => $lateMinutesTotal,
            'total_days' => $totalDays,
            'holiday_absent_count' => $holidayAbsentCount,
        ];
    }

    protected function summarizeOt(Employee $employee, PayrollPeriod $period, float $employeeHourlyRate): array
    {
        $rows = OtSessionEmployee::with('session')
            ->where('employee_id', $employee->id)
            ->whereHas('session', function ($q) use ($period) {
                $q->whereBetween('ot_date', [$period->start_date, $period->end_date]);
            })
            ->get();

        $hours = 0.0;
        $amount = 0.0;
        $details = [];

        foreach ($rows as $row) {
            $session = $row->session;
            $rate = $session->rate_mode === 'multiplier'
                ? round($employeeHourlyRate * (float) $session->multiplier, 2)
                : (float) $session->hourly_amount;
            $rowAmount = round($rate * (float) $row->hours, 2);
            $row->update([
                'hourly_rate_snapshot' => $rate,
                'total_amount' => $rowAmount,
            ]);
            $hours += (float) $row->hours;
            $amount += $rowAmount;
            $details[] = [
                'date' => $session->ot_date->toDateString(),
                'hours' => (float) $row->hours,
                'rate' => $rate,
                'amount' => $rowAmount,
            ];
        }

        return ['hours' => $hours, 'amount' => round($amount, 2), 'details' => $details];
    }

    protected function employeeComponents(Employee $employee, PayrollPeriod $period)
    {
        return EmployeeComponent::with('component')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->where('start_date', '<=', $period->end_date)
            ->where(function ($q) use ($period) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $period->start_date);
            })
            ->get();
    }

    protected function avgTaskRating(Employee $employee, PayrollPeriod $period): float
    {
        // TODO: คำนวณจาก task_assignees เมื่อระบบ task พร้อม ตอนนี้คืน 0
        return 0.0;
    }

    protected function evaluateMetric(string $trigger, array $att, array $ot): float
    {
        return match ($trigger) {
            'absent_count'           => (float) $att['absent_days'],
            'late_count'             => (float) $att['late_count'],
            'late_minutes_total'     => (float) $att['late_minutes_total'],
            'present_days'           => (float) $att['present_days'],
            'continuous_present_days' => (float) $att['present_days'], // simplification
            'ot_hours_total'         => (float) $ot['hours'],
            default                  => 0.0,
        };
    }

    protected function computeLateDeduction(CompensationProfile $p, array $att, float $hourlyRate, float $dailyRate): float
    {
        $minutes = max(0, $att['late_minutes_total'] - ($p->late_grace_minutes * $att['late_count']));
        return match ($p->late_deduction_method) {
            'per_minute'   => round($minutes * (float) $p->late_deduction_rate, 2),
            'per_hour'     => round(($minutes / 60) * (float) $p->late_deduction_rate, 2),
            'per_incident' => round($att['late_count'] * (float) $p->late_deduction_rate, 2),
            'fixed'        => $att['late_count'] > 0 ? (float) $p->late_deduction_rate : 0,
            default        => 0,
        };
    }

    protected function computeAbsentDeduction(CompensationProfile $p, array $att, float $dailyRate): float
    {
        return match ($p->absent_deduction_method) {
            'daily_wage' => round($att['absent_days'] * $dailyRate, 2),
            'fixed'      => round($att['absent_days'] * (float) $p->absent_deduction_amount, 2),
            default      => 0,
        };
    }

    protected function computeSsf(CompensationProfile $p, float $base): array
    {
        if (! $p->ssf_enabled) {
            return [0, 0];
        }
        $capped = min(max($base, (float) $p->ssf_min_base), (float) $p->ssf_max_base);
        $emp = round($capped * ((float) $p->ssf_rate) / 100, 2);
        return [$emp, $emp];
    }

    protected function computeTax(Employee $employee, float $grossPay, float $ssfEmp, PayrollPeriod $period, array &$meta): float
    {
        /** @var EmployeeTaxSetting|null $setting */
        $setting = EmployeeTaxSetting::with('taxProfile')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->first();

        if (! $setting || $setting->tax_method === 'none') {
            $meta = ['method' => 'none'];
            return 0;
        }

        if ($setting->tax_method === 'flat_amount') {
            $meta = ['method' => 'flat_amount', 'amount' => (float) $setting->flat_amount];
            return (float) $setting->flat_amount;
        }

        if ($setting->tax_method === 'fixed_rate') {
            $tax = round($grossPay * ((float) $setting->fixed_rate) / 100, 2);
            $meta = [
                'method' => 'fixed_rate',
                'rate' => (float) $setting->fixed_rate,
                'base' => $grossPay,
                'formula' => "{$grossPay} × {$setting->fixed_rate}%",
            ];
            return $tax;
        }

        // progressive
        $profile = $setting->taxProfile ?: TaxProfile::where('is_default', true)->first();
        if (! $profile) {
            $meta = ['method' => 'progressive', 'note' => 'no tax profile, skip'];
            return 0;
        }

        $payPeriodsPerYear = $this->payPeriodsPerYear($period);
        $annualGross = $grossPay * $payPeriodsPerYear;
        $annualSsf = $ssfEmp * $payPeriodsPerYear;

        // ค่าใช้จ่ายเหมา
        $expense = min($annualGross * ((float) $profile->expense_deduction_rate) / 100, (float) $profile->expense_deduction_max);

        $allowances = (float) $profile->personal_allowance
            + (float) $profile->spouse_allowance
            + ((int) $profile->children_count) * (float) $profile->child_allowance_each
            + (float) $profile->parent_allowance
            + (float) $profile->disabled_allowance
            + (float) $profile->life_insurance
            + (float) $profile->health_insurance
            + (float) $profile->provident_fund
            + (float) $profile->rmf_amount
            + (float) $profile->ssf_amount
            + (float) $profile->home_loan_interest
            + (float) $profile->donation_amount
            + $annualSsf;

        if (is_array($profile->extra_deductions)) {
            foreach ($profile->extra_deductions as $row) {
                $allowances += (float) ($row['amount'] ?? 0);
            }
        }

        $taxableIncome = max(0, $annualGross - $expense - $allowances);
        $annualTax = $this->progressiveTax($taxableIncome);
        $perPeriodTax = $setting->withhold_strategy === 'annualize'
            ? round($annualTax / $payPeriodsPerYear, 2)
            : round($annualTax, 2);

        $meta = [
            'method' => 'progressive',
            'tax_profile_id' => $profile->id,
            'periods_per_year' => $payPeriodsPerYear,
            'annual_gross' => round($annualGross, 2),
            'annual_ssf' => round($annualSsf, 2),
            'expense' => round($expense, 2),
            'allowances' => round($allowances, 2),
            'taxable_income' => round($taxableIncome, 2),
            'annual_tax' => round($annualTax, 2),
            'formula' => "annualize gross {$grossPay}×{$payPeriodsPerYear}, taxable={$taxableIncome}, annual_tax={$annualTax}",
        ];
        return $perPeriodTax;
    }

    protected function progressiveTax(float $taxableIncome): float
    {
        if ($taxableIncome <= 0) {
            return 0;
        }
        $brackets = TaxBracket::where('is_active', true)
            ->orderBy('order')
            ->orderBy('min_income')
            ->get();
        if ($brackets->isEmpty()) {
            return 0;
        }
        $tax = 0;
        foreach ($brackets as $b) {
            $min = (float) $b->min_income;
            $max = $b->max_income !== null ? (float) $b->max_income : INF;
            if ($taxableIncome <= $min) {
                break;
            }
            $taxable = min($taxableIncome, $max) - $min;
            if ($taxable > 0) {
                $tax += $taxable * ((float) $b->rate) / 100;
            }
        }
        return round($tax, 2);
    }

    protected function payPeriodsPerYear(PayrollPeriod $period): int
    {
        $days = $period->start_date->diffInDays($period->end_date) + 1;
        return match (true) {
            $days >= 28 => 12,
            $days >= 13 => 26,
            $days >= 6  => 52,
            default     => 365,
        };
    }

    protected function generateSlipNo(PayrollPeriod $period, Employee $employee): string
    {
        return sprintf('SLP-%s-%s-%05d', $period->code, $employee->employee_code ?? $employee->id, $employee->id);
    }

    protected function makeItem(
        PayrollSlip $slip, string $type, string $source, ?string $code, string $name,
        float $amount, int $order, bool $taxable = false, bool $ssf = false,
        ?float $quantity = null, ?float $rate = null,
        ?string $formula = null, ?int $referenceId = null, ?string $referenceType = null,
    ): PayrollSlipItem {
        return PayrollSlipItem::create([
            'payroll_slip_id' => $slip->id,
            'type' => $type,
            'source' => $source,
            'code' => $code,
            'name' => $name,
            'amount' => $amount,
            'quantity' => $quantity,
            'rate' => $rate,
            'taxable' => $taxable,
            'affects_ssf' => $ssf,
            'formula' => $formula,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'order' => $order,
        ]);
    }
}

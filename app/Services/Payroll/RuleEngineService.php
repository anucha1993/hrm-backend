<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Models\PayrollSetting;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipItem;
use Carbon\Carbon;

/**
 * Apply user-defined payroll_rules to a slip.
 * เรียกหลังจาก base/components/profile-rules ของ PayrollCalculationService
 */
class RuleEngineService
{
    /**
     * @param array $att       output ของ summarizeAttendance() ใน PayrollCalculationService
     * @param array $ot        output ของ summarizeOt()
     * @param array $extra     ['rating_avg' => float, 'leave_over_quota' => float]
     * @return array{items:array<array<string,mixed>>, deductions_total:float, bonus_total:float, log:array}
     */
    public function evaluate(
        Employee $employee,
        PayrollPeriod $period,
        float $baseSalary,
        array $att,
        array $ot,
        array $extra = [],
    ): array {
        $stats = $this->buildStats($employee, $period, $att, $ot, $extra);
        $rules = PayrollRule::query()
            ->where('active', true)
            ->where(function ($q) use ($period) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $period->end_date);
            })
            ->where(function ($q) use ($period) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $period->start_date);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $items = [];
        $dedTotal = 0.0;
        $bonusTotal = 0.0;
        $log = [];

        foreach ($rules as $rule) {
            if (! $this->ruleAppliesTo($rule, $employee, $period)) {
                continue;
            }
            $result = $this->applyRule($rule, $baseSalary, $stats);
            $log[] = ['rule' => $rule->code, 'name' => $rule->name, 'matched' => $result['matched'], 'amount' => $result['amount']];
            if (! $result['matched'] || $result['amount'] <= 0) {
                continue;
            }
            $items[] = [
                'rule'    => $rule,
                'amount'  => $result['amount'],
                'formula' => $result['formula'],
            ];
            if ($rule->type === 'bonus') {
                $bonusTotal += $result['amount'];
            } else {
                $dedTotal += $result['amount'];
            }
        }

        return [
            'items'             => $items,
            'deductions_total'  => round($dedTotal, 2),
            'bonus_total'       => round($bonusTotal, 2),
            'log'               => $log,
            'stats'             => $stats,
        ];
    }

    /**
     * เขียน items ลงตาราง payroll_slip_items และคืนยอดรวม
     * @return array{deductions:float, bonuses:float}
     */
    public function persistItems(PayrollSlip $slip, array $items, int &$order): array
    {
        $ded = 0.0;
        $bonus = 0.0;
        foreach ($items as $it) {
            /** @var PayrollRule $rule */
            $rule = $it['rule'];
            $amount = (float) $it['amount'];
            PayrollSlipItem::create([
                'payroll_slip_id' => $slip->id,
                'type'            => $rule->type === 'bonus' ? 'earning' : 'deduction',
                'source'          => 'rule',
                'code'            => $rule->code,
                'name'            => $rule->name,
                'amount'          => $amount,
                'taxable'         => $rule->type === 'bonus',
                'affects_ssf'     => false,
                'formula'         => $it['formula'],
                'reference_id'    => $rule->id,
                'reference_type'  => PayrollRule::class,
                'order'           => $order++,
            ]);
            if ($rule->type === 'bonus') {
                $bonus += $amount;
            } else {
                $ded += $amount;
            }
        }
        return ['deductions' => round($ded, 2), 'bonuses' => round($bonus, 2)];
    }

    /**
     * Apply global caps: max_deduction_percent + min_net_salary
     * @return array{adjusted_deductions:float, capped:bool, reason:?string}
     */
    public function applyGlobalCaps(float $baseSalary, float $deductionsTotal): array
    {
        $maxPct = (float) (PayrollSetting::get('max_deduction_percent') ?? 0);
        $minNet = (float) (PayrollSetting::get('min_net_salary') ?? 0);

        $capped = false;
        $reason = null;
        $adjusted = $deductionsTotal;

        if ($maxPct > 0) {
            $cap = round($baseSalary * $maxPct / 100, 2);
            if ($adjusted > $cap) {
                $adjusted = $cap;
                $capped = true;
                $reason = "หักรวมเกิน {$maxPct}% ของเงินเดือน (cap = {$cap})";
            }
        }

        if ($minNet > 0) {
            $maxAllowed = max(0, $baseSalary - $minNet);
            if ($adjusted > $maxAllowed) {
                $adjusted = $maxAllowed;
                $capped = true;
                $reason = "เงินสุทธิต้องไม่ต่ำกว่า {$minNet}";
            }
        }

        return ['adjusted_deductions' => round($adjusted, 2), 'capped' => $capped, 'reason' => $reason];
    }

    /* =================== Stats =================== */

    protected function buildStats(Employee $employee, PayrollPeriod $period, array $att, array $ot, array $extra): array
    {
        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;
        $tenureYears = $hireDate ? $hireDate->diffInYears(Carbon::parse($period->end_date)) : 0;

        // disqualifier flags
        $flags = [
            'absent'          => ($att['absent_days'] ?? 0) > 0,
            'late'            => ($att['late_count'] ?? 0) > 0,
            'early_leave'     => ($att['early_leave_count'] ?? 0) > 0,
            'missing_punch'   => ($att['missing_punch_count'] ?? 0) > 0,
            'leave_sick'      => ($att['leave_breakdown']['sick'] ?? 0) > 0,
            'leave_personal'  => ($att['leave_breakdown']['personal'] ?? 0) > 0,
            'leave_vacation'  => ($att['leave_breakdown']['vacation'] ?? 0) > 0,
            'leave_maternity' => ($att['leave_breakdown']['maternity'] ?? 0) > 0,
            'leave_other'     => ($att['leave_breakdown']['other'] ?? 0) > 0,
            'holiday_absent'  => ($att['holiday_absent_count'] ?? 0) > 0,
        ];

        return [
            'late_count'        => (int)   ($att['late_count'] ?? 0),
            'late_minutes'      => (int)   ($att['late_minutes_total'] ?? 0),
            'absent_count'      => (float) ($att['absent_days'] ?? 0),
            'early_leave_count' => (int)   ($att['early_leave_count'] ?? 0),
            'missing_punch'     => (int)   ($att['missing_punch_count'] ?? 0),
            'present_days'      => (float) ($att['present_days'] ?? 0),
            'rating_avg'        => (float) ($extra['rating_avg'] ?? 0),
            'tenure_years'      => (int)   $tenureYears,
            'ot_hours'          => (float) ($ot['hours'] ?? 0),
            'leave_over_quota'  => (float) ($extra['leave_over_quota'] ?? ($att['unpaid_leave_days'] ?? 0)),
            'disqualifier_flags'=> $flags,
        ];
    }

    /* =================== Rule application =================== */

    /**
     * @return array{matched:bool, amount:float, formula:?string}
     */
    protected function applyRule(PayrollRule $rule, float $baseSalary, array $stats): array
    {
        // 1. หาค่า metric
        $metric = $this->metricFor($rule, $stats);

        // 2. เช็คเงื่อนไข
        if ($rule->trigger === 'no_disqualifier') {
            $dq = $rule->disqualifiers ?? [];
            foreach ($dq as $key) {
                if (! empty($stats['disqualifier_flags'][$key])) {
                    return ['matched' => false, 'amount' => 0, 'formula' => "disqualified by {$key}"];
                }
            }
            return [
                'matched' => true,
                'amount'  => $this->boundedAmount($rule, $this->amountFor($rule, $baseSalary, 1, $stats)),
                'formula' => 'no_disqualifier passed',
            ];
        }

        // 3. ตามโหมด accumulation
        $mode = $rule->accumulation_mode;
        $thr = (int) ($rule->threshold ?? 0);

        if ($mode === 'tiered') {
            $best = $this->bestTier($rule->tiers ?? [], $metric);
            if ($best === null) {
                return ['matched' => false, 'amount' => 0, 'formula' => null];
            }
            return [
                'matched' => true,
                'amount'  => $this->boundedAmount($rule, (float) $best['amount']),
                'formula' => "tier threshold={$best['threshold']}, metric={$metric}",
            ];
        }

        if ($mode === 'repeating') {
            if ($thr <= 0 || $metric < $thr) {
                return ['matched' => false, 'amount' => 0, 'formula' => null];
            }
            $count = (int) floor($metric / $thr);
            return [
                'matched' => true,
                'amount'  => $this->boundedAmount($rule, $this->amountFor($rule, $baseSalary, $count, $stats)),
                'formula' => "repeating: floor({$metric}/{$thr})={$count}",
            ];
        }

        if ($mode === 'per_occurrence') {
            // ทุกครั้งที่ค่าผ่านเงื่อนไข comparison ต่อ threshold
            // simplification: ถ้าผ่าน → ใช้ metric เป็นจำนวนครั้ง
            if (! $this->compare($metric, $rule->comparison, $thr)) {
                return ['matched' => false, 'amount' => 0, 'formula' => null];
            }
            $count = max(1, (int) $metric);
            return [
                'matched' => true,
                'amount'  => $this->boundedAmount($rule, $this->amountFor($rule, $baseSalary, $count, $stats)),
                'formula' => "per_occurrence count={$count}",
            ];
        }

        // one_shot
        if (! $this->compare($metric, $rule->comparison, $thr)) {
            return ['matched' => false, 'amount' => 0, 'formula' => null];
        }
        return [
            'matched' => true,
            'amount'  => $this->boundedAmount($rule, $this->amountFor($rule, $baseSalary, 1, $stats)),
            'formula' => "one_shot metric={$metric} {$rule->comparison} {$thr}",
        ];
    }

    /**
     * เช็คว่ากฎนี้ครอบคลุมพนักงานคนนี้/งวดนี้หรือไม่ — department_ids (ว่าง=ทุกแผนก) + apply_months (ว่าง=ทุกงวด)
     * กฎ period='yearly' จะจ่ายเฉพาะงวดแรกของเดือนนั้น (start_date วันที่ <=15) เท่านั้น กันจ่ายซ้ำ 2 ครั้ง/ปี เพราะงวดจ่ายเงินเดือนเป็นแบบครึ่งเดือน (2 งวด/เดือน)
     */
    protected function ruleAppliesTo(PayrollRule $rule, Employee $employee, PayrollPeriod $period): bool
    {
        if (! empty($rule->department_ids) && ! in_array($employee->department_id, $rule->department_ids, true)) {
            return false;
        }
        if (! empty($rule->apply_months) && ! in_array((int) $period->start_date->month, array_map('intval', $rule->apply_months), true)) {
            return false;
        }
        if ($rule->period === 'yearly' && $period->start_date->day > 15) {
            return false;
        }
        return true;
    }

    protected function metricFor(PayrollRule $rule, array $stats): float
    {
        return (float) ($stats[$rule->trigger] ?? 0);
    }

    protected function compare(float $a, ?string $op, float $b): bool
    {
        return match ($op) {
            '>'     => $a > $b,
            '='     => abs($a - $b) < 0.0001,
            'every' => $b > 0 && $a >= $b, // ใช้กับ repeating; one_shot ไม่ควรใช้
            default => $a >= $b, // '>='
        };
    }

    protected function bestTier(array $tiers, float $metric): ?array
    {
        $matched = null;
        foreach ($tiers as $tier) {
            if (! isset($tier['threshold'], $tier['amount'])) continue;
            if ($metric >= (float) $tier['threshold']) {
                if ($matched === null || (float) $tier['threshold'] >= (float) $matched['threshold']) {
                    $matched = $tier;
                }
            }
        }
        return $matched;
    }

    /**
     * คำนวณจำนวนเงินตาม amount_type
     */
    protected function amountFor(PayrollRule $rule, float $baseSalary, int $count, array $stats): float
    {
        switch ($rule->amount_type) {
            case 'fixed':
                return (float) $rule->amount;

            case 'per_occurrence':
                return round((float) $rule->amount * $count, 2);

            case 'percent_salary':
                return round($baseSalary * (float) $rule->amount / 100, 2);

            case 'daily_rate':
                $divisor = max(1, (int) (PayrollSetting::get('daily_rate_divisor') ?? 30));
                $daily = $baseSalary / $divisor;
                $multiplier = (float) ($rule->amount > 0 ? $rule->amount : 1);
                return round($daily * $multiplier * $count, 2);

            case 'formula':
                $vars = [
                    'salary'       => $baseSalary,
                    'count'        => $count,
                    'minutes'      => $stats['late_minutes'] ?? 0,
                    'days'         => $stats['absent_count'] ?? 0,
                    'rating'       => $stats['rating_avg'] ?? 0,
                    'tenure_years' => $stats['tenure_years'] ?? 0,
                    'ot_hours'     => $stats['ot_hours'] ?? 0,
                ];
                return $this->evaluateFormula($rule->formula ?? '', $vars);
        }
        return 0.0;
    }

    protected function boundedAmount(PayrollRule $rule, float $amount): float
    {
        if ($rule->min_per_period !== null && $amount < (float) $rule->min_per_period) {
            $amount = (float) $rule->min_per_period;
        }
        if ($rule->max_per_period !== null && $amount > (float) $rule->max_per_period) {
            $amount = (float) $rule->max_per_period;
        }
        return round(max(0, $amount), 2);
    }

    /* =================== Safe formula evaluator =================== */

    /**
     * ประเมินสูตรอย่างปลอดภัย:
     * - แทน {var} ด้วยค่าตัวเลข
     * - อนุญาตเฉพาะ digit, . , + - * / ( ) space
     * - ใช้ evaluator แบบ shunting-yard
     */
    protected function evaluateFormula(string $formula, array $vars): float
    {
        // แทนตัวแปร
        $expr = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '0';
        }, $formula) ?? '';

        // ตรวจ whitelist
        if (! preg_match('/^[\d\.\+\-\*\/\(\)\s]+$/', $expr)) {
            return 0.0;
        }

        try {
            $result = $this->evalRpn($expr);
            return is_finite($result) ? round((float) $result, 2) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    protected function evalRpn(string $expr): float
    {
        // tokenize
        $tokens = [];
        $i = 0;
        $len = strlen($expr);
        while ($i < $len) {
            $c = $expr[$i];
            if (ctype_space($c)) { $i++; continue; }
            if (ctype_digit($c) || $c === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = (float) $num;
                continue;
            }
            // unary minus
            if ($c === '-' && (empty($tokens) || (is_string(end($tokens)) && in_array(end($tokens), ['+','-','*','/','(','u'])))) {
                $tokens[] = 'u';
                $i++;
                continue;
            }
            $tokens[] = $c;
            $i++;
        }

        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, 'u' => 3];
        $out = [];
        $ops = [];
        foreach ($tokens as $t) {
            if (is_float($t) || is_int($t)) {
                $out[] = $t;
            } elseif ($t === '(') {
                $ops[] = $t;
            } elseif ($t === ')') {
                while (! empty($ops) && end($ops) !== '(') { $out[] = array_pop($ops); }
                array_pop($ops);
            } else { // operator
                while (! empty($ops) && end($ops) !== '(' && ($prec[end($ops)] ?? 0) >= ($prec[$t] ?? 0)) {
                    $out[] = array_pop($ops);
                }
                $ops[] = $t;
            }
        }
        while (! empty($ops)) $out[] = array_pop($ops);

        // evaluate RPN
        $stack = [];
        foreach ($out as $t) {
            if (is_float($t) || is_int($t)) {
                $stack[] = (float) $t;
            } elseif ($t === 'u') {
                $a = array_pop($stack);
                $stack[] = -$a;
            } else {
                $b = array_pop($stack);
                $a = array_pop($stack);
                $stack[] = match ($t) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => $b == 0.0 ? 0.0 : $a / $b,
                    default => 0.0,
                };
            }
        }
        return (float) (end($stack) ?: 0.0);
    }
}

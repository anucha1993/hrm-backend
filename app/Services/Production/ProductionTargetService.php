<?php

namespace App\Services\Production;

use App\Models\Attendance;
use App\Models\PayrollPeriod;
use App\Models\ProductionAdvanceRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ตรวจสอบเงื่อนไข "ต้องผ่านเกณฑ์ก่อนจึงจะเบิกเงินล่วงหน้าได้"
 * รองรับ 2 แบบ: production_qty (ยอดผลิตจริงจาก Work Order) และ attendance_days (จำนวนวันมาทำงานของพนักงานคนนั้นในงวดปัจจุบัน)
 */
class ProductionTargetService
{
    /**
     * ยอดผลิตจริงสะสมของกฎ ณ วันที่กำหนด (ค่าเริ่มต้น = วันนี้) — ใช้กับ metric_type=production_qty
     */
    public function achievedQty(ProductionAdvanceRule $rule, ?Carbon $date = null): float
    {
        $date = ($date ?? Carbon::today())->toDateString();
        $itemIds = $rule->productionRateItems()->pluck('production_rate_items.id');
        if ($itemIds->isEmpty()) {
            return 0.0;
        }

        $q = DB::table('work_order_daily_entry_items as wodei')
            ->join('work_order_daily_entries as wode', 'wode.id', '=', 'wodei.work_order_daily_entry_id')
            ->join('work_order_items as woi', 'woi.id', '=', 'wodei.work_order_item_id')
            ->whereDate('wode.work_date', $date)
            ->whereIn('woi.production_rate_item_id', $itemIds);

        if ($rule->scope === ProductionAdvanceRule::SCOPE_DEPARTMENT && $rule->department_id) {
            $q->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
                ->join('employees as e', 'e.id', '=', 'wo.team_leader_id')
                ->where('e.department_id', $rule->department_id);
        }

        return (float) ($q->sum('wodei.actual_qty') ?? 0);
    }

    /**
     * จำนวนวันที่พนักงานคนนี้มาทำงาน (มีบันทึกลงเวลาอย่างน้อย 1 ครั้ง) นับตั้งแต่ต้นงวดเงินเดือนปัจจุบันจนถึงวันนี้
     * ใช้กับ metric_type=attendance_days (เช่น "ทำงานครบ 15 วันจึงจะเบิกได้")
     */
    public function achievedAttendanceDays(int $employeeId, ?Carbon $date = null): float
    {
        $today = $date ?? Carbon::today();
        $period = PayrollPeriod::whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->orderByDesc('start_date')
            ->first();
        if (! $period) {
            return 0.0;
        }

        return (float) Attendance::where('employee_id', $employeeId)
            ->whereBetween('checked_at', [$period->start_date->startOfDay(), $today->copy()->endOfDay()])
            ->get()
            ->groupBy(fn (Attendance $a) => $a->checked_at->toDateString())
            ->count();
    }

    /**
     * ประเมินผลกฎเดียว: เป้าหมาย / ยอดจริง / ผ่านหรือไม่
     */
    public function evaluate(ProductionAdvanceRule $rule, ?int $employeeId = null, ?Carbon $date = null): array
    {
        $date = $date ?? Carbon::today();
        $achieved = $rule->metric_type === ProductionAdvanceRule::METRIC_ATTENDANCE_DAYS
            ? ($employeeId ? $this->achievedAttendanceDays($employeeId, $date) : 0.0)
            : $this->achievedQty($rule, $date);

        return [
            'rule_id' => $rule->id,
            'name' => $rule->name,
            'unit' => $rule->unit,
            'metric_type' => $rule->metric_type,
            'scope' => $rule->scope,
            'department_id' => $rule->department_id,
            'target_qty' => (float) $rule->target_qty,
            'achieved_qty' => $achieved,
            'is_met' => $achieved >= (float) $rule->target_qty,
            'date' => $date->toDateString(),
        ];
    }

    /**
     * ประเมินกฎ active ทั้งหมดที่ "เกี่ยวข้อง" กับพนักงานคนนี้
     * ลำดับการเช็คว่าเกี่ยวข้องหรือไม่: ถ้ากำหนด applies_to_department_ids ไว้ ใช้อันนั้นตัดสิน (ไม่ว่า scope จะเป็นอะไร)
     * ถ้าไม่ได้กำหนด ใช้ scope เดิม (company=ทุกคน, department=เฉพาะแผนกที่ตั้งไว้ใน department_id)
     */
    public function evaluateForEmployee(?int $employeeId, ?int $employeeDepartmentId, ?Carbon $date = null): array
    {
        $rules = ProductionAdvanceRule::query()
            ->where('is_active', true)
            ->with('productionRateItems:id,name,category,work_type')
            ->get()
            ->filter(function (ProductionAdvanceRule $rule) use ($employeeDepartmentId) {
                if (! empty($rule->applies_to_department_ids)) {
                    return in_array($employeeDepartmentId, $rule->applies_to_department_ids, true);
                }
                if ($rule->scope === ProductionAdvanceRule::SCOPE_DEPARTMENT) {
                    return $rule->department_id === $employeeDepartmentId;
                }
                return true; // scope=company และไม่ได้จำกัดแผนก
            });

        return $rules->map(fn (ProductionAdvanceRule $rule) => $this->evaluate($rule, $employeeId, $date))->values()->all();
    }

    /**
     * true = ผ่านทุกเงื่อนไข (หรือไม่มีเงื่อนไขที่เกี่ยวข้องเลย), false = มีเงื่อนไขที่ยังไม่ถึงเป้า
     */
    public function isEligible(?int $employeeId, ?int $employeeDepartmentId, ?Carbon $date = null): array
    {
        $results = $this->evaluateForEmployee($employeeId, $employeeDepartmentId, $date);
        $failed = array_values(array_filter($results, fn ($r) => ! $r['is_met']));
        return [
            'eligible' => empty($failed),
            'rules' => $results,
            'failed_rules' => $failed,
        ];
    }
}

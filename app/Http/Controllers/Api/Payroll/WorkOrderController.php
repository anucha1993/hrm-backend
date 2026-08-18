<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyEntry;
use App\Models\WorkOrderItem;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    // ---------- WORK ORDERS ----------

    public function index(Request $request): JsonResponse
    {
        $q = WorkOrder::with(['teamLeader', 'payrollPeriod', 'items.rateItem'])
            ->withCount(['items', 'members', 'dailyEntries']);

        if ($from = $request->date('from')) $q->where('end_date', '>=', $from);
        if ($to = $request->date('to')) $q->where('start_date', '<=', $to);
        if ($status = $request->string('status')->toString()) $q->where('status', $status);
        if ($periodType = $request->string('period_type')->toString()) $q->where('period_type', $periodType);
        if ($leaderId = $request->integer('team_leader_id')) $q->where('team_leader_id', $leaderId);
        if ($periodId = $request->integer('payroll_period_id')) $q->where('payroll_period_id', $periodId);
        if ($batchCode = $request->string('batch_code')->toString()) $q->where('batch_code', $batchCode);
        if ($code = $request->string('code')->toString()) $q->where('code', 'like', "%{$code}%");

        $rows = $q->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(min(100, (int) $request->integer('per_page', 30)));

        return response()->json(['data' => $rows]);
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        $workOrder->load([
            'teamLeader',
            'payrollPeriod',
            'items.rateItem',
            'members.employee',
            'dailyEntries.items',
            'extraItems',
            'creator',
        ]);

        // ใบงานอื่นที่อยู่ "ลอตผลิตเดียวกัน" (batch_code ตรงกัน) — ช่วยให้เห็นว่าถูกแบ่งงานร่วมกับใบไหนบ้าง
        $linked = collect();
        if ($workOrder->batch_code) {
            $linked = WorkOrder::with('teamLeader')
                ->where('batch_code', $workOrder->batch_code)
                ->where('id', '!=', $workOrder->id)
                ->orderBy('code')
                ->get()
                ->map(fn (WorkOrder $w) => [
                    'id' => $w->id,
                    'code' => $w->code,
                    'status' => $w->status,
                    'total_amount' => $w->total_amount,
                    'team_leader_id' => $w->team_leader_id,
                    'team_leader_code' => $w->teamLeader?->employee_code,
                    'team_leader_name' => $w->teamLeader ? trim($w->teamLeader->first_name . ' ' . $w->teamLeader->last_name) : null,
                ])
                ->values();
        }

        $data = $workOrder->toArray();
        $data['linked_work_orders'] = $linked;
        $data['batch_total_amount'] = round((float) $workOrder->total_amount + $linked->sum('total_amount'), 2);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $wo = DB::transaction(function () use ($data, $request) {
            $wo = WorkOrder::create([
                'code' => WorkOrder::generateCode(),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'period_type' => $data['period_type'] ?? 'custom',
                'team_leader_id' => $data['team_leader_id'],
                'location_name' => $data['location_name'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'note' => $data['note'] ?? null,
                'batch_code' => $data['batch_code'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->saveItems($wo, $data['items']);
            $this->saveMembers($wo, $data['members'] ?? []);
            $this->saveExtras($wo, $data['extras'] ?? []);
            $wo->recalculate();
            $this->syncPayrollAfterRecalculate($wo);
            return $wo;
        });

        return $this->show($wo->fresh());
    }

    public function update(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status === 'paid') {
            return response()->json(['message' => 'ใบงานนี้จ่ายเงินแล้ว ไม่สามารถแก้ไขได้'], 422);
        }

        $data = $this->validateData($request, true);

        DB::transaction(function () use ($workOrder, $data) {
            $workOrder->update(array_filter([
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'period_type' => $data['period_type'] ?? null,
                'team_leader_id' => $data['team_leader_id'] ?? null,
                'location_name' => $data['location_name'] ?? null,
                'status' => $data['status'] ?? null,
                'note' => $data['note'] ?? null,
                'batch_code' => $data['batch_code'] ?? null,
            ], fn($v) => $v !== null));

            if (isset($data['items'])) {
                // ลบ items เก่า — cascade ลบ daily_entry_items ที่อ้างถึง item เก่าด้วย
                $workOrder->items()->delete();
                $this->saveItems($workOrder, $data['items']);
                // หาก items ใหม่ — daily_entries เดิมที่ไม่มี items แล้วจะแสดง qty=0 ตามปกติ
            }

            if (isset($data['members'])) {
                $workOrder->members()->delete();
                $this->saveMembers($workOrder, $data['members']);
            }

            if (isset($data['extras'])) {
                $workOrder->extraItems()->delete();
                $this->saveExtras($workOrder, $data['extras']);
            }

            $workOrder->recalculate();
            $this->syncPayrollAfterRecalculate($workOrder);
        });

        return $this->show($workOrder->fresh());
    }

    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status === 'paid') {
            return response()->json(['message' => 'ใบงานนี้จ่ายเงินแล้ว ไม่สามารถลบได้'], 422);
        }
        $workOrder->delete();
        return response()->json(['message' => 'deleted']);
    }

    /**
     * เชื่อมใบงานนี้กับใบงานอื่นให้เป็น "ลอตผลิตเดียวกัน" (batch_code เดียวกัน)
     * ทำงานแบบ 2 ทาง (ทั้งสองใบจะเห็นกันและกันเป็นลอตเดียวกันทันที) และรวมกลุ่มเดิม
     * ของทั้งสองฝั่ง (ถ้ามีใบอื่นผูกอยู่ก่อนแล้ว) เข้าด้วยกันเป็นรหัสเดียว
     */
    public function linkBatch(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'target_work_order_id' => ['required', 'integer', Rule::exists('work_orders', 'id')],
        ]);

        if ((int) $data['target_work_order_id'] === $workOrder->id) {
            return response()->json(['message' => 'ไม่สามารถเชื่อมใบงานกับตัวเองได้'], 422);
        }

        $target = WorkOrder::findOrFail($data['target_work_order_id']);

        DB::transaction(function () use ($workOrder, $target) {
            $code = $workOrder->batch_code ?: $target->batch_code ?: ('LOT-' . $workOrder->code);

            // รวมกลุ่มเดิมของทั้งสองฝั่ง (ถ้ามี) เข้าเป็นรหัสเดียวกัน เพื่อให้เชื่อมกันครบวงจร (transitive)
            $oldCodes = array_values(array_unique(array_filter([$workOrder->batch_code, $target->batch_code])));
            if ($oldCodes) {
                WorkOrder::whereIn('batch_code', $oldCodes)->update(['batch_code' => $code]);
            }
            $workOrder->update(['batch_code' => $code]);
            $target->update(['batch_code' => $code]);
        });

        return $this->show($workOrder->fresh());
    }

    /**
     * ยกเลิกการเชื่อมลอตผลิตของใบงานนี้ (ตัวเองออกจากกลุ่มเท่านั้น)
     * ถ้าเหลือใบเดียวในกลุ่มเดิม จะเคลียร์รหัสของใบที่เหลือด้วย (ผูกกับตัวเองคนเดียวไม่มีประโยชน์)
     */
    public function unlinkBatch(WorkOrder $workOrder): JsonResponse
    {
        DB::transaction(function () use ($workOrder) {
            $oldCode = $workOrder->batch_code;
            $workOrder->update(['batch_code' => null]);
            if ($oldCode) {
                $remaining = WorkOrder::where('batch_code', $oldCode)->get();
                if ($remaining->count() === 1) {
                    $remaining->first()->update(['batch_code' => null]);
                }
            }
        });

        return $this->show($workOrder->fresh());
    }

    // ---------- DAILY ENTRIES ----------

    public function storeDailyEntry(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status === 'paid') {
            return response()->json(['message' => 'ใบงานนี้จ่ายเงินแล้ว'], 422);
        }
        $data = $this->validateDailyEntry($request, $workOrder);

        DB::transaction(function () use ($workOrder, $data) {
            $entry = WorkOrderDailyEntry::updateOrCreate(
                ['work_order_id' => $workOrder->id, 'work_date' => $data['work_date']],
                ['note' => $data['note'] ?? null]
            );
            $entry->items()->delete();
            foreach ($data['items'] ?? [] as $row) {
                if (!isset($row['work_order_item_id'])) continue;
                $entry->items()->create([
                    'work_order_item_id' => $row['work_order_item_id'],
                    'assigned_qty' => $row['assigned_qty'] ?? 0,
                    'actual_qty' => $row['actual_qty'] ?? 0,
                ]);
            }
            $workOrder->refresh()->recalculate();
            $this->syncPayrollAfterRecalculate($workOrder);
        });

        return $this->show($workOrder->fresh());
    }

    public function updateDailyEntry(Request $request, WorkOrder $workOrder, WorkOrderDailyEntry $dailyEntry): JsonResponse
    {
        abort_unless($dailyEntry->work_order_id === $workOrder->id, 404);
        if ($workOrder->status === 'paid') {
            return response()->json(['message' => 'ใบงานนี้จ่ายเงินแล้ว'], 422);
        }
        $data = $this->validateDailyEntry($request, $workOrder);

        DB::transaction(function () use ($dailyEntry, $workOrder, $data) {
            $dailyEntry->update([
                'work_date' => $data['work_date'],
                'note' => $data['note'] ?? null,
            ]);
            $dailyEntry->items()->delete();
            foreach ($data['items'] ?? [] as $row) {
                if (!isset($row['work_order_item_id'])) continue;
                $dailyEntry->items()->create([
                    'work_order_item_id' => $row['work_order_item_id'],
                    'assigned_qty' => $row['assigned_qty'] ?? 0,
                    'actual_qty' => $row['actual_qty'] ?? 0,
                ]);
            }
            $workOrder->refresh()->recalculate();
            $this->syncPayrollAfterRecalculate($workOrder);
        });

        return $this->show($workOrder->fresh());
    }

    public function destroyDailyEntry(WorkOrder $workOrder, WorkOrderDailyEntry $dailyEntry): JsonResponse
    {
        abort_unless($dailyEntry->work_order_id === $workOrder->id, 404);
        if ($workOrder->status === 'paid') {
            return response()->json(['message' => 'ใบงานนี้จ่ายเงินแล้ว'], 422);
        }
        DB::transaction(function () use ($dailyEntry, $workOrder) {
            $dailyEntry->delete();
            $workOrder->refresh()->recalculate();
            $this->syncPayrollAfterRecalculate($workOrder);
        });
        return $this->show($workOrder->fresh());
    }

    /**
     * เมื่อ total_amount ของใบงานถูกคำนวณใหม่ (จากบันทึกผลรายวัน) และใบงานนี้
     * ถูกนำเข้า payroll ไปแล้ว (มี payroll_period_id) ให้ปรับยอด PayrollSlipItem
     * (PRODUCTION_WAGE) + gross_pay/net_pay ของ slip หัวหน้าทีมให้ตรงกับยอดใหม่ทันที
     * (รวมทุกใบงานของหัวหน้าทีมคนเดียวกันในงวดเดียวกัน ไม่ใช่แค่ใบนี้ใบเดียว)
     */
    private function syncPayrollAfterRecalculate(WorkOrder $workOrder): void
    {
        if (!$workOrder->payroll_period_id) return;

        $slip = PayrollSlip::where('payroll_period_id', $workOrder->payroll_period_id)
            ->where('employee_id', $workOrder->team_leader_id)
            ->first();
        if (!$slip) return;

        $orders = WorkOrder::where('payroll_period_id', $workOrder->payroll_period_id)
            ->where('team_leader_id', $workOrder->team_leader_id)
            ->get();
        $newAmount = round((float) $orders->sum('total_amount'), 2);
        $count = $orders->count();

        $item = PayrollSlipItem::where('payroll_slip_id', $slip->id)->where('code', 'PRODUCTION_WAGE')->first();
        $oldAmount = $item ? (float) $item->amount : 0.0;

        if ($item) {
            $item->update([
                'amount' => $newAmount,
                'quantity' => $count,
                'name' => "ค่าจ้างการผลิต ({$count} ใบงาน)",
            ]);
        } else {
            $item = PayrollSlipItem::create([
                'payroll_slip_id' => $slip->id, 'type' => 'earning', 'source' => 'production',
                'code' => 'PRODUCTION_WAGE', 'name' => "ค่าจ้างการผลิต ({$count} ใบงาน)",
                'amount' => $newAmount, 'quantity' => $count, 'rate' => 0,
                'taxable' => true, 'affects_ssf' => true, 'reference_type' => 'work_order',
            ]);
        }

        $delta = round($newAmount - $oldAmount, 2);
        $slip->gross_pay = round((float) $slip->gross_pay + $delta, 2);
        $slip->net_pay = round((float) $slip->net_pay + $delta, 2);
        $slip->save();
    }

    // ---------- SUMMARY + PAYROLL IMPORT ----------

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['completed', 'paid', 'all'])],
        ]);
        $status = $data['status'] ?? 'completed';

        $q = DB::table('work_orders as a')
            ->join('employees as emp', 'emp.id', '=', 'a.team_leader_id')
            ->whereNull('a.deleted_at')
            ->where('a.start_date', '>=', $data['from'])
            ->where('a.end_date', '<=', $data['to']);

        if ($status !== 'all') $q->where('a.status', $status);

        $rows = $q->groupBy('a.team_leader_id', 'emp.employee_code', 'emp.first_name', 'emp.last_name')
            ->selectRaw('a.team_leader_id as employee_id, emp.employee_code, emp.first_name, emp.last_name,
                COUNT(a.id) as work_orders_count,
                SUM(a.total_amount) as total_amount')
            ->orderBy('emp.employee_code')
            ->get();

        return response()->json([
            'data' => [
                'rows' => $rows,
                'totals' => [
                    'leaders' => $rows->count(),
                    'amount' => $rows->sum('total_amount'),
                ],
            ],
        ]);
    }

    public function importToPayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['required', 'exists:payroll_periods,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $orders = WorkOrder::where('start_date', '>=', $data['from'])
            ->where('end_date', '<=', $data['to'])
            ->where('status', 'completed')
            ->whereNull('payroll_period_id')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'ไม่พบใบจ่ายงานที่พร้อมนำเข้า'], 422);
        }

        $byLeader = [];
        foreach ($orders as $o) {
            $lid = $o->team_leader_id;
            $byLeader[$lid]['amount'] = ($byLeader[$lid]['amount'] ?? 0) + (float) $o->total_amount;
            $byLeader[$lid]['count'] = ($byLeader[$lid]['count'] ?? 0) + 1;
        }

        $imported = 0;
        $skipped = 0;
        DB::transaction(function () use ($byLeader, $data, $orders, &$imported, &$skipped) {
            foreach ($byLeader as $leaderId => $sum) {
                $slip = PayrollSlip::where('payroll_period_id', $data['payroll_period_id'])
                    ->where('employee_id', $leaderId)
                    ->first();
                if (!$slip) { $skipped++; continue; }
                PayrollSlipItem::create([
                    'payroll_slip_id' => $slip->id,
                    'type' => 'earning',
                    'source' => 'production',
                    'code' => 'PRODUCTION_WAGE',
                    'name' => "ค่าจ้างการผลิต ({$sum['count']} ใบงาน)",
                    'amount' => $sum['amount'],
                    'quantity' => $sum['count'],
                    'rate' => 0,
                    'taxable' => true,
                    'affects_ssf' => true,
                    'reference_type' => 'work_order',
                ]);
                $imported++;
            }
            // ผูกใบงานทุกใบเข้ากับงวดนี้ (เฉพาะที่มี slip เท่านั้น) — ยังไม่เปลี่ยนสถานะเป็น "จ่ายแล้ว"
            // จนกว่างวดเงินเดือนนี้จะถูกจ่ายจริง (ดู PayrollPeriodController::update)
            foreach ($orders as $o) {
                $slip = PayrollSlip::where('payroll_period_id', $data['payroll_period_id'])
                    ->where('employee_id', $o->team_leader_id)
                    ->first();
                if (!$slip) continue;
                $o->update([
                    'payroll_period_id' => $data['payroll_period_id'],
                ]);
            }
        });

        return response()->json([
            'message' => "นำเข้าสำเร็จ {$imported} หัวหน้าทีม" . ($skipped ? " (ข้าม {$skipped} คน ที่ไม่มี slip)" : ''),
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }

    // ---------- helpers ----------

    private function validateData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'start_date' => [$req, 'date'],
            'end_date' => [$req, 'date', 'after_or_equal:start_date'],
            'period_type' => ['sometimes', Rule::in(['daily', 'biweekly_1', 'biweekly_2', 'monthly', 'custom'])],
            'team_leader_id' => [$req, 'exists:employees,id'],
            'location_name' => ['nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['draft', 'in_progress', 'completed'])],
            'note' => ['nullable', 'string', 'max:500'],
            'batch_code' => ['nullable', 'string', 'max:40'],
            'items' => [$req, 'array', 'min:1'],
            'items.*.production_rate_item_id' => ['required', 'exists:production_rate_items,id'],
            'items.*.target_qty' => ['required', 'numeric', 'min:0'],
            'items.*.rate_at_target_override' => ['nullable', 'numeric', 'min:0'],
            'items.*.rate_below_target_override' => ['nullable', 'numeric', 'min:0'],
            'members' => ['sometimes', 'array'],
            'members.*.employee_id' => ['required', 'exists:employees,id'],
            'members.*.role' => ['nullable', 'string', 'max:50'],
            'members.*.note' => ['nullable', 'string', 'max:255'],
            'extras' => ['sometimes', 'array'],
            'extras.*.name' => ['required_with:extras', 'string', 'max:255'],
            'extras.*.unit' => ['nullable', 'string', 'max:50'],
            'extras.*.qty' => ['required_with:extras', 'numeric', 'min:0'],
            // ราคา/หน่วยติดลบได้ ใช้แทนรายการหัก (เช่น หักค่าเสียหาย) ในตารางเดียวกันกับรายการจ่ายเพิ่มเติม
            'extras.*.rate' => ['required_with:extras', 'numeric'],
            'extras.*.note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function validateDailyEntry(Request $request, WorkOrder $wo): array
    {
        return $request->validate([
            'work_date' => [
                'required', 'date',
                'after_or_equal:' . $wo->start_date->format('Y-m-d'),
                'before_or_equal:' . $wo->end_date->format('Y-m-d'),
            ],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.work_order_item_id' => [
                'required',
                Rule::exists('work_order_items', 'id')->where('work_order_id', $wo->id),
            ],
            'items.*.assigned_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.actual_qty' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function saveItems(WorkOrder $wo, array $items): void
    {
        foreach (array_values($items) as $idx => $it) {
            $wo->items()->create([
                'production_rate_item_id' => $it['production_rate_item_id'],
                'target_qty' => $it['target_qty'],
                'rate_at_target_override' => $it['rate_at_target_override'] ?? null,
                'rate_below_target_override' => $it['rate_below_target_override'] ?? null,
                'sort_order' => $idx,
            ]);
        }
    }

    private function saveMembers(WorkOrder $wo, array $members): void
    {
        $seen = [];
        foreach ($members as $m) {
            $empId = (int) $m['employee_id'];
            if ($empId === (int) $wo->team_leader_id) continue;
            if (isset($seen[$empId])) continue;
            $seen[$empId] = true;
            $wo->members()->create([
                'employee_id' => $empId,
                'role' => $m['role'] ?? null,
                'note' => $m['note'] ?? null,
            ]);
        }
    }

    private function saveExtras(WorkOrder $wo, array $extras): void
    {
        foreach (array_values($extras) as $idx => $e) {
            $qty = (float) ($e['qty'] ?? 0);
            $rate = (float) ($e['rate'] ?? 0);
            $wo->extraItems()->create([
                'name' => $e['name'],
                'unit' => $e['unit'] ?? null,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
                'note' => $e['note'] ?? null,
                'sort_order' => $idx,
            ]);
        }
    }
}

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
            'creator',
        ]);
        return response()->json(['data' => $workOrder]);
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
                'created_by' => $request->user()?->id,
            ]);
            $this->saveItems($wo, $data['items']);
            $this->saveMembers($wo, $data['members'] ?? []);
            $wo->recalculate();
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

            $workOrder->recalculate();
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
        });
        return $this->show($workOrder->fresh());
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
            // ปิดใบงานทุกใบที่อยู่ใน byLeader (เฉพาะที่มี slip เท่านั้น)
            foreach ($orders as $o) {
                $slip = PayrollSlip::where('payroll_period_id', $data['payroll_period_id'])
                    ->where('employee_id', $o->team_leader_id)
                    ->first();
                if (!$slip) continue;
                $o->update([
                    'status' => 'paid',
                    'payroll_period_id' => $data['payroll_period_id'],
                    'paid_at' => now(),
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
            'items' => [$req, 'array', 'min:1'],
            'items.*.production_rate_item_id' => ['required', 'exists:production_rate_items,id'],
            'items.*.target_qty' => ['required', 'numeric', 'min:0'],
            'items.*.rate_at_target_override' => ['nullable', 'numeric', 'min:0'],
            'items.*.rate_below_target_override' => ['nullable', 'numeric', 'min:0'],
            'members' => ['sometimes', 'array'],
            'members.*.employee_id' => ['required', 'exists:employees,id'],
            'members.*.role' => ['nullable', 'string', 'max:50'],
            'members.*.note' => ['nullable', 'string', 'max:255'],
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
}

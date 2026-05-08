<?php

namespace App\Services\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestLog;
use App\Models\LeaveType;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /**
     * คำนวณจำนวนวันลา (รองรับครึ่งวัน)
     */
    public function calculateDays(string $startDate, string $endDate, bool $isHalfDay): float
    {
        if ($isHalfDay) {
            return 0.5;
        }
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => 'วันสิ้นสุดต้องไม่อยู่ก่อนวันเริ่ม']);
        }
        return $start->diffInDays($end) + 1;
    }

    /**
     * สร้างคำขอลา (ตรวจ overlap, quota, advance notice)
     */
    public function create(array $data, int $userId): LeaveRequest
    {
        return DB::transaction(function () use ($data, $userId) {
            $type = LeaveType::findOrFail($data['leave_type_id']);
            if (! $type->is_active) {
                throw ValidationException::withMessages(['leave_type_id' => 'ประเภทการลานี้ปิดใช้งาน']);
            }

            $isHalfDay = (bool) ($data['is_half_day'] ?? false);
            if ($isHalfDay && ! $type->allow_half_day) {
                throw ValidationException::withMessages(['is_half_day' => 'ประเภทนี้ไม่อนุญาตลาครึ่งวัน']);
            }

            $totalDays = $this->calculateDays($data['start_date'], $data['end_date'], $isHalfDay);

            // ตรวจ max consecutive
            if ($type->max_consecutive_days && $totalDays > $type->max_consecutive_days) {
                throw ValidationException::withMessages([
                    'total_days' => "ลาประเภทนี้สูงสุด {$type->max_consecutive_days} วันต่อครั้ง",
                ]);
            }

            // ตรวจ advance notice
            if ($type->min_advance_notice_days > 0) {
                $start = Carbon::parse($data['start_date']);
                $today = Carbon::today();
                if ($start->diffInDays($today, false) > -$type->min_advance_notice_days) {
                    throw ValidationException::withMessages([
                        'start_date' => "ต้องยื่นล่วงหน้าอย่างน้อย {$type->min_advance_notice_days} วัน",
                    ]);
                }
            }

            // ตรวจซ้อน (overlap) คำขอที่ยังไม่ปฏิเสธ/ยกเลิก
            $overlap = LeaveRequest::where('employee_id', $data['employee_id'])
                ->whereIn('status', ['pending', 'approved'])
                ->where(function ($q) use ($data) {
                    $q->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                      ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                      ->orWhere(function ($q2) use ($data) {
                          $q2->where('start_date', '<=', $data['start_date'])
                             ->where('end_date', '>=', $data['end_date']);
                      });
                })
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_date' => 'ช่วงวันที่ทับซ้อนกับคำขอลาเดิม',
                ]);
            }

            // ตรวจ balance
            $year = Carbon::parse($data['start_date'])->year;
            $balance = $this->getOrCreateBalance($data['employee_id'], $type->id, $year);
            $remaining = (float) $balance->quota_days + (float) $balance->carryover_days
                - (float) $balance->used_days - (float) $balance->pending_days;
            if (! $type->allow_negative_balance && $totalDays > $remaining) {
                throw ValidationException::withMessages([
                    'total_days' => "วันลาคงเหลือไม่พอ (เหลือ {$remaining} วัน)",
                ]);
            }

            $request = LeaveRequest::create([
                'request_no' => $this->generateRequestNo(),
                'employee_id' => $data['employee_id'],
                'leave_type_id' => $type->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_half_day' => $isHalfDay,
                'half_day_period' => $data['half_day_period'] ?? null,
                'total_days' => $totalDays,
                'reason' => $data['reason'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'status' => $type->requires_approval ? 'pending' : 'approved',
            ]);

            // อัปเดต balance pending
            if ($request->status === 'pending') {
                $balance->increment('pending_days', $totalDays);
            } else {
                $balance->increment('used_days', $totalDays);
            }

            $this->log($request, 'submit', null, $request->status, $userId, $data['reason'] ?? null);

            return $request->load('leaveType', 'employee');
        });
    }

    public function approve(LeaveRequest $request, int $userId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $userId, $note) {
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'ไม่สามารถอนุมัติคำขอที่ไม่อยู่ในสถานะรออนุมัติได้']);
            }
            $from = $request->status;
            $request->update([
                'status' => 'approved',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $year = $request->start_date->year;
            $balance = $this->getOrCreateBalance($request->employee_id, $request->leave_type_id, $year);
            $balance->decrement('pending_days', (float) $request->total_days);
            $balance->increment('used_days', (float) $request->total_days);

            $this->log($request, 'approve', $from, 'approved', $userId, $note);
            return $request->fresh(['leaveType', 'employee']);
        });
    }

    public function reject(LeaveRequest $request, int $userId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $userId, $note) {
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'ไม่สามารถปฏิเสธคำขอที่ไม่อยู่ในสถานะรออนุมัติได้']);
            }
            $from = $request->status;
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $year = $request->start_date->year;
            $balance = $this->getOrCreateBalance($request->employee_id, $request->leave_type_id, $year);
            $balance->decrement('pending_days', (float) $request->total_days);

            $this->log($request, 'reject', $from, 'rejected', $userId, $note);
            return $request->fresh(['leaveType', 'employee']);
        });
    }

    public function cancel(LeaveRequest $request, int $userId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $userId, $note) {
            if (in_array($request->status, ['cancelled', 'rejected'])) {
                throw ValidationException::withMessages(['status' => 'คำขอนี้ปิดอยู่แล้ว']);
            }
            $from = $request->status;
            $year = $request->start_date->year;
            $balance = $this->getOrCreateBalance($request->employee_id, $request->leave_type_id, $year);
            if ($from === 'pending') {
                $balance->decrement('pending_days', (float) $request->total_days);
            } elseif ($from === 'approved') {
                $balance->decrement('used_days', (float) $request->total_days);
            }
            $request->update(['status' => 'cancelled']);
            $this->log($request, 'cancel', $from, 'cancelled', $userId, $note);
            return $request->fresh(['leaveType', 'employee']);
        });
    }

    /**
     * นับวันลา (paid + unpaid + per type) ที่ approved ในช่วงวันที่
     * ใช้โดย PayrollCalculationService
     */
    public function summarizeForPeriod(int $employeeId, string $startDate, string $endDate): array
    {
        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            })
            ->get();

        $totalDays = 0.0;
        $paidDays = 0.0;
        $unpaidDays = 0.0;
        $countsAsWorkDays = 0.0;
        $byType = [];

        foreach ($requests as $r) {
            // นับเฉพาะวันที่อยู่ในช่วงงวด
            $rStart = $r->start_date->greaterThan($startDate) ? $r->start_date->copy() : Carbon::parse($startDate);
            $rEnd = $r->end_date->lessThan($endDate) ? $r->end_date->copy() : Carbon::parse($endDate);
            $days = $r->is_half_day ? 0.5 : ($rStart->diffInDays($rEnd) + 1);
            $totalDays += $days;
            if ($r->leaveType->is_paid) $paidDays += $days;
            else $unpaidDays += $days;
            if ($r->leaveType->counts_as_workday) $countsAsWorkDays += $days;

            $code = $r->leaveType->code;
            if (! isset($byType[$code])) {
                $byType[$code] = ['code' => $code, 'name' => $r->leaveType->name, 'is_paid' => $r->leaveType->is_paid, 'days' => 0.0];
            }
            $byType[$code]['days'] += $days;
        }

        return [
            'total_days' => $totalDays,
            'paid_days' => $paidDays,
            'unpaid_days' => $unpaidDays,
            'counts_as_workday_days' => $countsAsWorkDays,
            'by_type' => array_values($byType),
        ];
    }

    public function getOrCreateBalance(int $employeeId, int $leaveTypeId, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'year' => $year],
            ['quota_days' => optional(LeaveType::find($leaveTypeId))->default_quota_days ?? 0],
        );
    }

    protected function log(LeaveRequest $request, string $action, ?string $from, ?string $to, int $userId, ?string $note): void
    {
        LeaveRequestLog::create([
            'leave_request_id' => $request->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $userId,
        ]);
    }

    protected function generateRequestNo(): string
    {
        $prefix = 'LV' . now()->format('ym');
        $count = LeaveRequest::where('request_no', 'like', $prefix . '%')->count() + 1;
        return $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}

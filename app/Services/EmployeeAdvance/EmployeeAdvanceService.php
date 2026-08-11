<?php

namespace App\Services\EmployeeAdvance;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAdvanceService
{
    /**
     * สร้างคำขอเบิกเงินล่วงหน้า
     */
    public function create(array $data, int $userId): EmployeeAdvance
    {
        return DB::transaction(function () use ($data, $userId) {
            $advance = EmployeeAdvance::create([
                'request_no' => $this->generateRequestNo(),
                'employee_id' => $data['employee_id'],
                'amount' => $data['amount'],
                'reason' => $data['reason'] ?? null,
                'request_date' => $data['request_date'] ?? now()->toDateString(),
                'status' => EmployeeAdvance::STATUS_PENDING,
                'created_by' => $userId,
            ]);
            return $advance->load('employee');
        });
    }

    public function approve(EmployeeAdvance $advance, int $userId, ?string $note = null): EmployeeAdvance
    {
        if ($advance->status !== EmployeeAdvance::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'อนุมัติได้เฉพาะคำขอที่รออนุมัติ']);
        }
        $advance->update([
            'status' => EmployeeAdvance::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_note' => $note,
        ]);
        return $advance->fresh(['employee']);
    }

    public function reject(EmployeeAdvance $advance, int $userId, ?string $note = null): EmployeeAdvance
    {
        if ($advance->status !== EmployeeAdvance::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'ปฏิเสธได้เฉพาะคำขอที่รออนุมัติ']);
        }
        $advance->update([
            'status' => EmployeeAdvance::STATUS_REJECTED,
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_note' => $note,
        ]);
        return $advance->fresh(['employee']);
    }

    public function cancel(EmployeeAdvance $advance, int $userId, ?string $note = null): EmployeeAdvance
    {
        if (! in_array($advance->status, [EmployeeAdvance::STATUS_PENDING, EmployeeAdvance::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะคำขอที่ยังไม่จ่ายเงิน']);
        }
        $advance->update([
            'status' => EmployeeAdvance::STATUS_CANCELLED,
            'approval_note' => $note ?? $advance->approval_note,
        ]);
        return $advance->fresh(['employee']);
    }

    /**
     * บันทึกว่าจ่ายเงินเบิกล่วงหน้าให้พนักงานแล้ว (เริ่มนับหักคืน)
     */
    public function markPaid(EmployeeAdvance $advance, int $userId): EmployeeAdvance
    {
        if ($advance->status !== EmployeeAdvance::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'ต้องอนุมัติก่อนจึงจะจ่ายเงินได้']);
        }
        $advance->update([
            'status' => EmployeeAdvance::STATUS_PAID,
            'paid_by' => $userId,
            'paid_at' => now(),
        ]);
        return $advance->fresh(['employee']);
    }

    /**
     * บันทึกการหักคืนเงินเบิกล่วงหน้า (เช่น หักจากเงินเดือนงวดใดงวดหนึ่ง)
     */
    public function addRepayment(EmployeeAdvance $advance, array $data, int $userId): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $data, $userId) {
            if (! in_array($advance->status, [EmployeeAdvance::STATUS_PAID, EmployeeAdvance::STATUS_COMPLETED], true)) {
                throw ValidationException::withMessages(['status' => 'บันทึกการหักคืนได้เฉพาะรายการที่จ่ายเงินแล้ว']);
            }
            $remaining = round((float) $advance->amount - (float) $advance->repaid_amount, 2);
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'จำนวนเงินต้องมากกว่า 0']);
            }
            if ($amount > $remaining + 0.01) {
                throw ValidationException::withMessages(['amount' => "จำนวนเงินเกินยอดคงเหลือ (คงเหลือ {$remaining})"]);
            }

            EmployeeAdvanceRepayment::create([
                'employee_advance_id' => $advance->id,
                'payroll_period_id' => $data['payroll_period_id'] ?? null,
                'amount' => $amount,
                'repaid_at' => $data['repaid_at'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $userId,
            ]);

            $newRepaid = round((float) $advance->repaid_amount + $amount, 2);
            $advance->update([
                'repaid_amount' => $newRepaid,
                'status' => $newRepaid >= (float) $advance->amount - 0.01
                    ? EmployeeAdvance::STATUS_COMPLETED
                    : EmployeeAdvance::STATUS_PAID,
            ]);

            return $advance->fresh(['employee', 'repayments']);
        });
    }

    public function deleteRepayment(EmployeeAdvanceRepayment $repayment): EmployeeAdvance
    {
        return DB::transaction(function () use ($repayment) {
            $advance = $repayment->advance;
            $repayment->delete();
            $newRepaid = round((float) $advance->repayments()->sum('amount'), 2);
            $advance->update([
                'repaid_amount' => $newRepaid,
                'status' => $newRepaid >= (float) $advance->amount - 0.01
                    ? EmployeeAdvance::STATUS_COMPLETED
                    : EmployeeAdvance::STATUS_PAID,
            ]);
            return $advance->fresh(['employee', 'repayments']);
        });
    }

    protected function generateRequestNo(): string
    {
        $prefix = 'ADV' . now()->format('ym');
        $count = EmployeeAdvance::withTrashed()->where('request_no', 'like', $prefix . '%')->count() + 1;
        return $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}

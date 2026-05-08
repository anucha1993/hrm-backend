<?php

namespace App\Services\Payroll;

use App\Models\PayrollApproval;
use App\Models\PayrollSlip;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollApprovalService
{
    /**
     * HR ส่งสลิปเข้ารอบอนุมัติ Manager
     */
    public function submitToL1(PayrollSlip $slip, int $userId, ?string $note = null): PayrollSlip
    {
        return $this->transition($slip, $userId, 'submit_l1', ['computed', 'rejected'], 'pending_l1', $note);
    }

    public function approveL1(PayrollSlip $slip, int $userId, ?string $note = null): PayrollSlip
    {
        return DB::transaction(function () use ($slip, $userId, $note) {
            $this->ensureFrom($slip, ['pending_l1']);
            $slip->update([
                'status' => 'pending_l2',
                'approved_l1_by' => $userId,
                'approved_l1_at' => now(),
            ]);
            $this->log($slip, 'approve_l1', 'pending_l1', 'pending_l2', $userId, $note);
            return $slip->fresh();
        });
    }

    public function rejectL1(PayrollSlip $slip, int $userId, ?string $note = null): PayrollSlip
    {
        return $this->transition($slip, $userId, 'reject_l1', ['pending_l1'], 'rejected', $note);
    }

    public function approveL2(PayrollSlip $slip, int $userId, ?string $note = null): PayrollSlip
    {
        return DB::transaction(function () use ($slip, $userId, $note) {
            $this->ensureFrom($slip, ['pending_l2']);
            $slip->update([
                'status' => 'approved',
                'approved_l2_by' => $userId,
                'approved_l2_at' => now(),
            ]);
            $this->log($slip, 'approve_l2', 'pending_l2', 'approved', $userId, $note);
            return $slip->fresh();
        });
    }

    public function rejectL2(PayrollSlip $slip, int $userId, ?string $note = null): PayrollSlip
    {
        return $this->transition($slip, $userId, 'reject_l2', ['pending_l2'], 'rejected', $note);
    }

    public function markPaid(PayrollSlip $slip, int $userId, ?string $reference = null, ?string $note = null): PayrollSlip
    {
        return DB::transaction(function () use ($slip, $userId, $reference, $note) {
            $this->ensureFrom($slip, ['approved']);
            $slip->update([
                'status' => 'paid',
                'paid_by' => $userId,
                'paid_at' => now(),
                'payment_reference' => $reference,
            ]);
            $this->log($slip, 'mark_paid', 'approved', 'paid', $userId, $note);
            return $slip->fresh();
        });
    }

    public function cancel(PayrollSlip $slip, int $userId, ?string $note = null): PayrollSlip
    {
        if ($slip->status === 'paid') {
            throw new RuntimeException('สลิปจ่ายเงินแล้ว ยกเลิกไม่ได้');
        }
        $from = $slip->status;
        $slip->update(['status' => 'cancelled']);
        $this->log($slip, 'cancel', $from, 'cancelled', $userId, $note);
        return $slip->fresh();
    }

    /* ----- helpers ----- */

    protected function transition(PayrollSlip $slip, int $userId, string $action, array $allowFrom, string $to, ?string $note): PayrollSlip
    {
        return DB::transaction(function () use ($slip, $userId, $action, $allowFrom, $to, $note) {
            $this->ensureFrom($slip, $allowFrom);
            $from = $slip->status;
            $slip->update(['status' => $to]);
            $this->log($slip, $action, $from, $to, $userId, $note);
            return $slip->fresh();
        });
    }

    protected function ensureFrom(PayrollSlip $slip, array $allowed): void
    {
        if (! in_array($slip->status, $allowed, true)) {
            throw new RuntimeException("สถานะปัจจุบัน '{$slip->status}' เปลี่ยนเป็นเป้าหมายไม่ได้ (ต้องเป็น: " . implode(',', $allowed) . ')');
        }
    }

    protected function log(PayrollSlip $slip, string $action, ?string $from, ?string $to, int $userId, ?string $note): void
    {
        PayrollApproval::create([
            'payroll_slip_id' => $slip->id,
            'payroll_period_id' => $slip->payroll_period_id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'note' => $note,
        ]);
    }
}

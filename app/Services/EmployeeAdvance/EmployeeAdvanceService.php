<?php

namespace App\Services\EmployeeAdvance;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Services\Production\ProductionTargetService;
use App\Services\TigerVoucher\TigerVoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeAdvanceService
{
    public function __construct(
        protected ProductionTargetService $productionTargetService,
        protected TigerVoucherService $tigerVoucherService,
    ) {}

    /**
     * สร้างคำขอเบิกเงินล่วงหน้า
     * เช็คเงื่อนไขเป้าหมายผลิต/วันมาทำงานของพนักงานคนนี้ก่อนเสมอ (server-side) — ถ้าไม่ผ่านและไม่ได้ขอข้ามเงื่อนไข (พร้อมเหตุผล) จะสร้างคำขอไม่ได้
     */
    public function create(array $data, int $userId, bool $bypassEligibility = false, ?string $bypassReason = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($data, $userId, $bypassEligibility, $bypassReason) {
            $departmentId = \App\Models\Employee::whereKey($data['employee_id'])->value('department_id');
            $eligibility = $this->productionTargetService->isEligible((int) $data['employee_id'], $departmentId);
            $bypassed = false;
            if (! $eligibility['eligible']) {
                if (! $bypassEligibility) {
                    $names = collect($eligibility['failed_rules'])->pluck('name')->implode(', ');
                    throw ValidationException::withMessages([
                        'production_target' => "พนักงานยังไม่ผ่านเงื่อนไข ({$names}) จึงยังบันทึกคำขอเบิกเงินไม่ได้",
                    ]);
                }
                if (! $bypassReason || trim($bypassReason) === '') {
                    throw ValidationException::withMessages([
                        'bypass_reason' => 'กรุณาระบุเหตุผลการข้ามเงื่อนไข',
                    ]);
                }
                $bypassed = true;
            }

            $advance = EmployeeAdvance::create([
                'request_no' => $this->generateRequestNo(),
                'employee_id' => $data['employee_id'],
                'amount' => $data['amount'],
                'reason' => $data['reason'] ?? null,
                'request_date' => $data['request_date'] ?? now()->toDateString(),
                'status' => EmployeeAdvance::STATUS_PENDING,
                'created_by' => $userId,
                'eligibility_bypassed' => $bypassed,
                'eligibility_bypass_reason' => $bypassed ? $bypassReason : null,
                'eligibility_bypass_by' => $bypassed ? $userId : null,
                'eligibility_bypass_at' => $bypassed ? now() : null,
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
     * disbursementMethod='tiger_voucher' จะถูกตรวจเป้าผลิต + เรียก TigerPay สร้าง voucher ให้ก่อน (ดู payViaTigerVoucher)
     * $bypassEligibility+$bypassReason = ข้ามเงื่อนไขเป้าผลิตกรณีพิเศษ (ต้องระบุเหตุผล บันทึกเก็บไว้เป็นหลักฐาน)
     */
    public function markPaid(
        EmployeeAdvance $advance,
        int $userId,
        string $disbursementMethod = 'manual',
        bool $bypassEligibility = false,
        ?string $bypassReason = null,
    ): EmployeeAdvance {
        if ($advance->status !== EmployeeAdvance::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'ต้องอนุมัติก่อนจึงจะจ่ายเงินได้']);
        }

        if ($disbursementMethod === 'tiger_voucher') {
            return $this->payViaTigerVoucher($advance, $userId, $bypassEligibility, $bypassReason);
        }

        $advance->update([
            'status' => EmployeeAdvance::STATUS_PAID,
            'disbursement_method' => 'manual',
            'paid_by' => $userId,
            'paid_at' => now(),
        ]);
        return $advance->fresh(['employee']);
    }

    /**
     * จ่ายเงินผ่านเครื่อง Tiger (TigerPay voucher) — ตรวจเป้าผลิตของวันนี้ก่อนเสมอ (server-side, ห้ามข้ามแม้ frontend เช็คแล้ว)
     * กรณีพิเศษ: อนุญาตให้ข้ามเงื่อนไขได้ถ้าระบุเหตุผล (เก็บ log ไว้เสมอเพื่อการตรวจสอบ)
     */
    public function payViaTigerVoucher(
        EmployeeAdvance $advance,
        int $userId,
        bool $bypassEligibility = false,
        ?string $bypassReason = null,
    ): EmployeeAdvance {
        $departmentId = $advance->employee?->department_id;
        $eligibility = $this->productionTargetService->isEligible($advance->employee_id, $departmentId);
        if (! $eligibility['eligible']) {
            if (! $bypassEligibility) {
                $names = collect($eligibility['failed_rules'])->pluck('name')->implode(', ');
                throw ValidationException::withMessages([
                    'production_target' => "ยังผลิตไม่ถึงเป้าที่กำหนด ({$names}) จึงเบิกผ่านเครื่อง Tiger ไม่ได้",
                ]);
            }
            if (! $bypassReason || trim($bypassReason) === '') {
                throw ValidationException::withMessages([
                    'bypass_reason' => 'กรุณาระบุเหตุผลการข้ามเงื่อนไข',
                ]);
            }
        }

        $refNum = (string) Str::uuid();
        $result = $this->tigerVoucherService->createVoucher([
            'amount' => (float) $advance->amount,
            'number_of_voucher' => 1,
            'start_date' => now()->format('d-m-Y'),
            'expire_date' => now()->addYear()->format('d-m-Y'),
            'note' => "เบิกเงินล่วงหน้า {$advance->request_no}",
            'authen_required' => 0,
            'ref_num' => $refNum,
            'category' => 'Advance',
        ]);

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'tiger_voucher' => $result['message'] ?? 'สร้าง Tiger Voucher ไม่สำเร็จ กรุณาลองใหม่',
            ]);
        }

        // TigerPay ตอบ voucher code เป็น array ที่ key 'result' (1 รายการ เพราะเราขอ number_of_voucher=1 เสมอ)
        $voucherData = $result['data'] ?? [];
        $codes = $voucherData['result'] ?? [];
        $voucherCode = is_array($codes) ? ($codes[0] ?? null) : $codes;

        $advance->update([
            'status' => EmployeeAdvance::STATUS_PAID,
            'disbursement_method' => 'tiger_voucher',
            'paid_by' => $userId,
            'paid_at' => now(),
            'tiger_voucher_code' => $voucherCode,
            'tiger_voucher_ref_num' => $refNum,
            'tiger_voucher_status' => 'created',
            'tiger_voucher_response' => $result['raw'] ?? null,
            'tiger_voucher_issued_at' => now(),
            'eligibility_bypassed' => ! $eligibility['eligible'],
            'eligibility_bypass_reason' => ! $eligibility['eligible'] ? $bypassReason : null,
            'eligibility_bypass_by' => ! $eligibility['eligible'] ? $userId : null,
            'eligibility_bypass_at' => ! $eligibility['eligible'] ? now() : null,
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

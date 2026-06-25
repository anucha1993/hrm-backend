<?php

namespace App\Services;

use App\Models\ShiftDayOverride;
use App\Models\ShiftSwapRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * จัดการ workflow คำขอสลับกะ:
 *   - อนุมัติ  -> สร้าง ShiftDayOverride ทั้งสองฝั่ง (materialize)
 *   - ปฏิเสธ  -> เปลี่ยนสถานะเฉย ๆ
 *   - ยกเลิก  -> ถ้าเคยอนุมัติแล้ว ให้ลบ override ที่เกิดจากคำขอนี้ (revert)
 *
 * ความหมายของการสลับ:
 *   - สลับวันเดียวกัน (requester_date == counterparty_date): แลกกะกันในวันนั้น
 *   - สลับคนละวัน: ต่างคนต่างไปทำกะของอีกฝ่ายในวันของอีกฝ่าย และหยุดในวันเดิมของตน
 */
class ShiftSwapService
{
    /**
     * อนุมัติคำขอ + สร้าง override
     *
     * @throws ValidationException เมื่อมี override เดิมชนกัน
     */
    public function approve(ShiftSwapRequest $request, ?int $approverUserId, ?string $note = null): ShiftSwapRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'คำขอนี้ถูกดำเนินการไปแล้ว',
            ]);
        }

        $specs = $this->buildOverrideSpecs($request);
        $this->assertNoConflict($request, $specs);

        DB::transaction(function () use ($request, $specs, $approverUserId, $note) {
            foreach ($specs as $spec) {
                ShiftDayOverride::create([
                    'employee_id'          => $spec['employee_id'],
                    'date'                 => $spec['date'],
                    'work_shift_id'        => $spec['work_shift_id'],
                    'is_day_off'           => $spec['is_day_off'],
                    'source'               => 'swap',
                    'shift_swap_request_id' => $request->id,
                    'note'                 => 'สลับกะ #' . $request->id,
                    'created_by'           => $approverUserId,
                ]);
            }

            $request->update([
                'status'        => 'approved',
                'approved_by'   => $approverUserId,
                'decided_at'    => now(),
                'decision_note' => $note,
            ]);
        });

        return $request->refresh();
    }

    public function reject(ShiftSwapRequest $request, ?int $approverUserId, ?string $note = null): ShiftSwapRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'คำขอนี้ถูกดำเนินการไปแล้ว',
            ]);
        }

        $request->update([
            'status'        => 'rejected',
            'approved_by'   => $approverUserId,
            'decided_at'    => now(),
            'decision_note' => $note,
        ]);

        return $request->refresh();
    }

    /**
     * ยกเลิกคำขอ — ถ้าเคยอนุมัติแล้ว ให้ revert override ออกด้วย
     */
    public function cancel(ShiftSwapRequest $request): ShiftSwapRequest
    {
        if (in_array($request->status, ['rejected', 'cancelled'], true)) {
            return $request;
        }

        DB::transaction(function () use ($request) {
            $request->overrides()->delete();
            $request->update([
                'status'     => 'cancelled',
                'decided_at' => now(),
            ]);
        });

        return $request->refresh();
    }

    /**
     * สร้างรายการ override ที่ต้องเกิดจากคำขอนี้
     *
     * @return array<int, array{employee_id:int, date:string, work_shift_id:?int, is_day_off:bool}>
     */
    private function buildOverrideSpecs(ShiftSwapRequest $request): array
    {
        $rDate = Carbon::parse($request->requester_date)->toDateString();
        $cDate = Carbon::parse($request->counterparty_date)->toDateString();
        $rShift = $request->requester_shift_id;
        $cShift = $request->counterparty_shift_id;

        if ($rDate === $cDate) {
            // สลับกะวันเดียวกัน: แลกกะ
            return [
                $this->spec($request->requester_id, $rDate, $cShift),
                $this->spec($request->counterparty_id, $cDate, $rShift),
            ];
        }

        // สลับคนละวัน: ไปทำกะของอีกฝ่ายในวันของอีกฝ่าย + หยุดวันเดิมของตน
        return [
            $this->spec($request->requester_id, $rDate, null, true),
            $this->spec($request->requester_id, $cDate, $cShift),
            $this->spec($request->counterparty_id, $cDate, null, true),
            $this->spec($request->counterparty_id, $rDate, $rShift),
        ];
    }

    private function spec(int $employeeId, string $date, ?int $shiftId, bool $forceDayOff = false): array
    {
        $isDayOff = $forceDayOff || $shiftId === null;
        return [
            'employee_id'   => $employeeId,
            'date'          => $date,
            'work_shift_id' => $isDayOff ? null : $shiftId,
            'is_day_off'    => $isDayOff,
        ];
    }

    /**
     * กันชน override เดิม (เช่น ปรับมือไว้ก่อน หรือคำขออื่นจองวันนี้แล้ว)
     */
    private function assertNoConflict(ShiftSwapRequest $request, array $specs): void
    {
        foreach ($specs as $spec) {
            $existing = ShiftDayOverride::where('employee_id', $spec['employee_id'])
                ->whereDate('date', $spec['date'])
                ->first();
            if ($existing) {
                $emp = $existing->employee_id === $request->requester_id
                    ? $request->requester
                    : $request->counterparty;
                throw ValidationException::withMessages([
                    'conflict' => 'มีการกำหนดกะรายวันของ "' . ($emp->full_name ?? ('#' . $spec['employee_id']))
                        . '" วันที่ ' . $spec['date'] . ' อยู่แล้ว กรุณาลบรายการเดิมก่อน',
                ]);
            }
        }
    }
}

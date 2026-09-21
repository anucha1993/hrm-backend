<?php

namespace App\Services\Dorm;

use App\Models\DormRoom;
use App\Models\ElectricityBill;
use App\Models\ElectricityBillInstallment;
use App\Models\ElectricityBillItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ElectricityBillService
{
    /**
     * สร้างบิลใหม่ (draft) สำหรับเดือนที่ระบุ — ดึงห้องที่ active ทั้งหมดมาเป็นรายการเริ่มต้น
     * เลขมิเตอร์ก่อน = เลขมิเตอร์ล่าสุดของห้องนั้น (ต่อยอดจากบิลก่อนหน้าอัตโนมัติ)
     */
    public function create(array $data, ?int $userId = null): ElectricityBill
    {
        return DB::transaction(function () use ($data, $userId) {
            $month = Carbon::parse($data['bill_month'])->startOfMonth();
            $rate = (float) ($data['rate_per_unit'] ?? 4);

            $bill = ElectricityBill::create([
                'code' => ElectricityBill::generateCode($month),
                'bill_month' => $month,
                'rate_per_unit' => $rate,
                'status' => ElectricityBill::STATUS_DRAFT,
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncRooms($bill);

            return $bill->fresh(['items.room', 'items.employee']);
        });
    }

    /**
     * เพิ่มรายการห้อง active ที่ยังไม่มีอยู่ในบิลนี้ (เผื่อมีห้องใหม่เพิ่มทีหลัง)
     */
    public function syncRooms(ElectricityBill $bill): ElectricityBill
    {
        $this->assertDraft($bill);

        $existingRoomIds = $bill->items()->pluck('dorm_room_id')->all();
        $rooms = DormRoom::where('is_active', true)->whereNotIn('id', $existingRoomIds)->orderBy('room_no')->get();

        $order = $bill->items()->max('order') ?? 0;
        foreach ($rooms as $room) {
            $order++;
            ElectricityBillItem::create([
                'electricity_bill_id' => $bill->id,
                'dorm_room_id' => $room->id,
                'employee_id' => $room->employee_id,
                'meter_start' => $room->last_meter_reading,
                'meter_end' => null,
                'rate_per_unit' => $bill->rate_per_unit,
                'room_rent' => $room->rent_amount,
                'order' => $order,
            ]);
        }

        return $bill->fresh(['items.room', 'items.employee']);
    }

    /**
     * แก้ไขรายการห้องในบิล (เลขมิเตอร์หลัง/ค่าห้อง/หมายเหตุ) — คำนวณยอดใหม่ทันที
     */
    public function updateItem(ElectricityBillItem $item, array $data): ElectricityBillItem
    {
        $this->assertDraft($item->bill);

        if (array_key_exists('meter_end', $data)) {
            $item->meter_end = $data['meter_end'];
        }
        if (array_key_exists('room_rent', $data)) {
            $item->room_rent = $data['room_rent'];
        }
        if (array_key_exists('note', $data)) {
            $item->note = $data['note'];
        }
        if (array_key_exists('employee_id', $data)) {
            $item->employee_id = $data['employee_id'];
        }
        $item->recompute();

        return $item->fresh();
    }

    /**
     * ปิดรอบบิล — ล็อกไม่ให้แก้ไขต่อ, อัปเดตเลขมิเตอร์ล่าสุดของแต่ละห้อง,
     * และสร้างงวดหักเงินเดือน 2 งวด (แบ่งครึ่งยอดรวม) ต่อรายการที่มีผู้เช่า
     */
    public function finalize(ElectricityBill $bill, string $dueDate1, string $dueDate2): ElectricityBill
    {
        $this->assertDraft($bill);

        return DB::transaction(function () use ($bill, $dueDate1, $dueDate2) {
            $items = $bill->items()->with('room')->get();
            foreach ($items as $item) {
                if ($item->meter_end === null) {
                    throw new RuntimeException("ห้อง {$item->room->room_no} ยังไม่ได้กรอกเลขมิเตอร์หลัง");
                }

                // อัปเดตเลขมิเตอร์ล่าสุดของห้อง เพื่อใช้เป็นเลขก่อนของบิลรอบถัดไป
                $item->room->update(['last_meter_reading' => $item->meter_end]);

                if ($item->employee_id) {
                    $half1 = round((float) $item->total_amount / 2, 2);
                    $half2 = round((float) $item->total_amount - $half1, 2);

                    ElectricityBillInstallment::create([
                        'electricity_bill_item_id' => $item->id,
                        'employee_id' => $item->employee_id,
                        'installment_no' => 1,
                        'due_date' => $dueDate1,
                        'amount' => $half1,
                        'status' => ElectricityBillInstallment::STATUS_PENDING,
                    ]);
                    ElectricityBillInstallment::create([
                        'electricity_bill_item_id' => $item->id,
                        'employee_id' => $item->employee_id,
                        'installment_no' => 2,
                        'due_date' => $dueDate2,
                        'amount' => $half2,
                        'status' => ElectricityBillInstallment::STATUS_PENDING,
                    ]);
                }
            }

            $bill->update(['status' => ElectricityBill::STATUS_FINALIZED, 'finalized_at' => now()]);

            return $bill->fresh(['items.room', 'items.employee', 'items.installments']);
        });
    }

    public function destroy(ElectricityBill $bill): void
    {
        if ($bill->status === ElectricityBill::STATUS_FINALIZED) {
            throw new RuntimeException('บิลนี้ปิดรอบแล้ว ไม่สามารถลบได้');
        }
        $bill->delete();
    }

    private function assertDraft(ElectricityBill $bill): void
    {
        if ($bill->status !== ElectricityBill::STATUS_DRAFT) {
            throw new RuntimeException('บิลนี้ปิดรอบแล้ว ไม่สามารถแก้ไขได้');
        }
    }
}

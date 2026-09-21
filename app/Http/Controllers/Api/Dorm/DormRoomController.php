<?php

namespace App\Http\Controllers\Api\Dorm;

use App\Http\Controllers\Controller;
use App\Models\DormRoom;
use App\Models\ElectricityBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DormRoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = DormRoom::with('employee:id,employee_code,first_name,last_name')->orderBy('room_no');
        if ($request->has('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $room = DormRoom::create($data);
        return response()->json(['data' => $room->load('employee:id,employee_code,first_name,last_name')], 201);
    }

    public function update(Request $request, DormRoom $room): JsonResponse
    {
        $data = $this->validateData($request, $room->id);
        $room->update($data);
        return response()->json(['data' => $room->fresh()->load('employee:id,employee_code,first_name,last_name')]);
    }

    public function destroy(DormRoom $room): JsonResponse
    {
        $hasFinalizedBill = $room->items()
            ->whereHas('bill', fn ($q) => $q->where('status', ElectricityBill::STATUS_FINALIZED))
            ->exists();
        if ($hasFinalizedBill) {
            return response()->json(['message' => 'ห้องนี้มีใบค่าไฟที่ปิดรอบแล้ว ไม่สามารถลบได้ — ใช้การปิดใช้งานแทน'], 422);
        }

        DB::transaction(function () use ($room) {
            // ห้องนี้อาจมีรายการอยู่ในบิล "ฉบับร่าง" เท่านั้น (ยังไม่ปิดรอบ) — ลบรายการเหล่านั้นออกก่อน
            // เพื่อให้ลบห้องได้ (FK ของ electricity_bill_items.dorm_room_id เป็น restrictOnDelete)
            $room->items()->delete();
            $room->delete();
        });

        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'room_no' => ['required', 'string', 'max:30', Rule::unique('dorm_rooms', 'room_no')->ignore($ignoreId)],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'rent_amount' => ['required', 'numeric', 'min:0'],
            'last_meter_reading' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}

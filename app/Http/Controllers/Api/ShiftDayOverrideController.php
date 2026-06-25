<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftDayOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShiftDayOverrideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ShiftDayOverride::query()
            ->with([
                'employee:id,employee_code,title,first_name,last_name,birth_date,department_id',
                'workShift:id,name,start_time,end_time',
            ])
            ->orderByDesc('date');

        if ($request->filled('employee_id')) {
            $q->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('source')) {
            $q->where('source', $request->query('source'));
        }
        if ($request->filled('from')) {
            $q->whereDate('date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('date', '<=', $request->query('to'));
        }

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'   => ['required', 'exists:employees,id'],
            'date'          => ['required', 'date'],
            'work_shift_id' => ['nullable', 'exists:work_shifts,id'],
            'is_day_off'    => ['boolean'],
            'note'          => ['nullable', 'string', 'max:500'],
        ]);

        $isDayOff = (bool) ($data['is_day_off'] ?? false);
        if (! $isDayOff && empty($data['work_shift_id'])) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'กรุณาเลือกกะ หรือกำหนดให้เป็นวันหยุด',
            ]);
        }

        // กันชนกับ override ที่เกิดจากการสลับกะ
        $existing = ShiftDayOverride::where('employee_id', $data['employee_id'])
            ->whereDate('date', $data['date'])
            ->first();
        if ($existing && $existing->source !== 'manual') {
            throw ValidationException::withMessages([
                'date' => 'วันนี้มีการกำหนดกะจากการสลับกะอยู่แล้ว ไม่สามารถปรับมือทับได้',
            ]);
        }

        $override = ShiftDayOverride::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            [
                'work_shift_id' => $isDayOff ? null : $data['work_shift_id'],
                'is_day_off'    => $isDayOff,
                'source'        => 'manual',
                'note'          => $data['note'] ?? null,
                'created_by'    => $request->user()?->id,
            ]
        );

        $override->load([
            'employee:id,employee_code,title,first_name,last_name,birth_date,department_id',
            'workShift:id,name,start_time,end_time',
        ]);

        return response()->json(['data' => $override], 201);
    }

    public function destroy(ShiftDayOverride $shiftDayOverride): JsonResponse
    {
        if ($shiftDayOverride->source === 'swap') {
            throw ValidationException::withMessages([
                'source' => 'รายการนี้มาจากการสลับกะ กรุณายกเลิกที่คำขอสลับกะแทน',
            ]);
        }

        $shiftDayOverride->delete();

        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}

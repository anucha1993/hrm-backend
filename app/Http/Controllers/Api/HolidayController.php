<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Holiday::query()
            ->with('workProfile:id,name')
            ->orderBy('date');

        // กรองตามขอบเขต: global = วันหยุดกลาง, หรือเฉพาะโปรไฟล์
        if ($request->filled('scope')) {
            if ($request->query('scope') === 'global') {
                $q->whereNull('work_profile_id');
            }
        }
        if ($request->filled('work_profile_id')) {
            $q->where('work_profile_id', $request->integer('work_profile_id'));
        }
        if ($request->filled('year')) {
            $year = $request->integer('year');
            // recurring แสดงทุกปี; เฉพาะวันที่เจาะจงกรองตามปี
            $q->where(function ($w) use ($year) {
                $w->where('is_recurring', true)
                    ->orWhereYear('date', $year);
            });
        }
        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $holiday = Holiday::create($data);

        return response()->json(['data' => $holiday->load('workProfile:id,name')], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $data = $this->validateData($request);
        $holiday->update($data);

        return response()->json(['data' => $holiday->load('workProfile:id,name')]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'work_profile_id' => ['nullable', 'exists:work_profiles,id'],
            'name'            => ['required', 'string', 'max:255'],
            'date'            => ['required', 'date'],
            'is_recurring'    => ['boolean'],
            'is_working'      => ['boolean'],
            'is_active'       => ['boolean'],
        ]);
    }
}

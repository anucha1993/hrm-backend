<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = WorkShift::query()->orderBy('name');
        if ($request->boolean('active_only')) $q->where('is_active', true);
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $shift = WorkShift::create($data);
        return response()->json(['data' => $shift], 201);
    }

    public function update(Request $request, WorkShift $workShift): JsonResponse
    {
        $data = $this->validateData($request);
        $workShift->update($data);
        return response()->json(['data' => $workShift]);
    }

    public function destroy(WorkShift $workShift): JsonResponse
    {
        $workShift->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'               => ['required', 'string', 'max:255'],
            'start_time'         => ['required', 'date_format:H:i'],
            'end_time'           => ['required', 'date_format:H:i'],
            'break_minutes'      => ['nullable', 'integer', 'min:0', 'max:480'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'cross_midnight'     => ['boolean'],
            'is_active'          => ['boolean'],
        ]);
    }
}

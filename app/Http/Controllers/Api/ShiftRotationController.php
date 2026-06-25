<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeRotation;
use App\Models\ShiftRotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftRotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ShiftRotation::query()
            ->withCount('assignments')
            ->orderBy('name');

        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function show(ShiftRotation $shiftRotation): JsonResponse
    {
        $shiftRotation->load([
            'assignments.employee:id,employee_code,title,first_name,last_name,birth_date,department_id',
            'assignments.employee.department:id,name',
        ]);

        return response()->json(['data' => $shiftRotation]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $rotation = ShiftRotation::create($data);

        return response()->json(['data' => $rotation], 201);
    }

    public function update(Request $request, ShiftRotation $shiftRotation): JsonResponse
    {
        $data = $this->validateData($request);
        $shiftRotation->update($data);

        return response()->json(['data' => $shiftRotation]);
    }

    public function destroy(ShiftRotation $shiftRotation): JsonResponse
    {
        $shiftRotation->delete();

        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /* ----------- การมอบหมายพนักงานเข้ารอบ ----------- */

    public function storeAssignment(Request $request, ShiftRotation $shiftRotation): JsonResponse
    {
        $data = $request->validate([
            'employee_id'    => ['required', 'exists:employees,id'],
            'offset'         => ['nullable', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $assignment = $shiftRotation->assignments()->create([
            'employee_id'    => $data['employee_id'],
            'offset'         => $data['offset'] ?? 0,
            'effective_from' => $data['effective_from'],
            'effective_to'   => $data['effective_to'] ?? null,
        ]);

        $assignment->load('employee:id,employee_code,title,first_name,last_name,birth_date,department_id');

        return response()->json(['data' => $assignment], 201);
    }

    public function destroyAssignment(ShiftRotation $shiftRotation, EmployeeRotation $assignment): JsonResponse
    {
        abort_unless($assignment->shift_rotation_id === $shiftRotation->id, 404);
        $assignment->delete();

        return response()->json(['message' => 'นำออกจากรอบเรียบร้อย']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'sequence'      => ['required', 'array', 'min:1'],
            'sequence.*'    => ['nullable', 'integer', 'exists:work_shifts,id'], // null = วันหยุดของช่วงนั้น
            'days_per_step' => ['nullable', 'integer', 'min:1', 'max:90'],
            'anchor_date'   => ['required', 'date'],
            'description'   => ['nullable', 'string'],
            'is_active'     => ['boolean'],
        ]);
    }
}

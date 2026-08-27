<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Department::orderBy('name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'            => ['required', 'string', 'max:50', 'unique:departments,code'],
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'attendance_mode' => ['nullable', Rule::in(Department::ATTENDANCE_MODES)],
            'is_active'       => ['boolean'],
        ]);
        return response()->json(['data' => Department::create($data)], 201);
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json(['data' => $department]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'code'            => ['sometimes', 'string', 'max:50', Rule::unique('departments', 'code')->ignore($department->id)],
            'name'            => ['sometimes', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'attendance_mode' => ['sometimes', Rule::in(Department::ATTENDANCE_MODES)],
            'is_active'       => ['boolean'],
        ]);
        $department->update($data);
        return response()->json(['data' => $department]);
    }

    public function destroy(Department $department): JsonResponse
    {
        if ($department->employees()->exists()) {
            return response()->json(['message' => 'มีพนักงานสังกัดแผนกนี้อยู่'], 422);
        }
        $department->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmploymentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmploymentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = EmploymentType::orderBy('name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'        => ['required', 'string', 'max:50', 'unique:employment_types,code'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ]);
        return response()->json(['data' => EmploymentType::create($data)], 201);
    }

    public function show(EmploymentType $employmentType): JsonResponse
    {
        return response()->json(['data' => $employmentType]);
    }

    public function update(Request $request, EmploymentType $employmentType): JsonResponse
    {
        $data = $request->validate([
            'code'        => ['sometimes', 'string', 'max:50', Rule::unique('employment_types', 'code')->ignore($employmentType->id)],
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ]);
        $employmentType->update($data);
        return response()->json(['data' => $employmentType]);
    }

    public function destroy(EmploymentType $employmentType): JsonResponse
    {
        if ($employmentType->employees()->exists()) {
            return response()->json(['message' => 'มีพนักงานใช้ประเภทการจ้างนี้อยู่'], 422);
        }
        $employmentType->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}

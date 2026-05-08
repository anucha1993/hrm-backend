<?php

namespace App\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = LeaveType::orderBy('order')->orderBy('id');
        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }
        return response()->json(['data' => $q->get()]);
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        return response()->json(['data' => $leaveType]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        return response()->json(['data' => LeaveType::create($data)], 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $data = $request->validate($this->rules($leaveType));
        $leaveType->update($data);
        return response()->json(['data' => $leaveType]);
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        if ($leaveType->requests()->exists()) {
            return response()->json(['message' => 'มีคำขอลาผูกอยู่ ลบไม่ได้ (แนะนำให้ปิดใช้งานแทน)'], 422);
        }
        $leaveType->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function rules(?LeaveType $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        return [
            'code' => [$req, 'string', 'max:50',
                $existing ? Rule::unique('leave_types', 'code')->ignore($existing->id)->whereNull('deleted_at')
                          : Rule::unique('leave_types', 'code')->whereNull('deleted_at')],
            'name' => [$req, 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:20'],
            'is_paid' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'counts_as_workday' => ['sometimes', 'boolean'],
            'affects_diligence' => ['sometimes', 'boolean'],
            'default_quota_days' => ['sometimes', 'numeric', 'min:0'],
            'min_advance_notice_days' => ['sometimes', 'integer', 'min:0'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'allow_negative_balance' => ['sometimes', 'boolean'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

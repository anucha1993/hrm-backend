<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\WorkProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = WorkProfile::query()
            ->with('workShift:id,name,start_time,end_time')
            ->withCount(['holidays', 'departments', 'employees'])
            ->orderByDesc('is_default')
            ->orderBy('name');

        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function show(WorkProfile $workProfile): JsonResponse
    {
        $workProfile->load([
            'workShift',
            'holidays' => fn ($q) => $q->orderBy('date'),
            'departments:id,code,name,work_profile_id',
        ]);

        return response()->json(['data' => $workProfile]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $profile = DB::transaction(function () use ($data) {
            $profile = WorkProfile::create($data);
            $this->ensureSingleDefault($profile);
            return $profile;
        });

        return response()->json(['data' => $profile->load('workShift')], 201);
    }

    public function update(Request $request, WorkProfile $workProfile): JsonResponse
    {
        $data = $this->validateData($request);

        DB::transaction(function () use ($workProfile, $data) {
            $workProfile->update($data);
            $this->ensureSingleDefault($workProfile);
        });

        return response()->json(['data' => $workProfile->load('workShift')]);
    }

    public function destroy(WorkProfile $workProfile): JsonResponse
    {
        if ($workProfile->is_default) {
            return response()->json(['message' => 'ไม่สามารถลบโปรไฟล์ค่าเริ่มต้นได้ กรุณาตั้งโปรไฟล์อื่นเป็นค่าเริ่มต้นก่อน'], 422);
        }

        $workProfile->delete(); // FK nullOnDelete จะ unset ที่แผนก/พนักงานเอง, holidays cascade

        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /** ตั้งว่าแผนกใดบ้างใช้โปรไฟล์นี้ (ตั้งค่าแบบ exact set) */
    public function syncDepartments(Request $request, WorkProfile $workProfile): JsonResponse
    {
        $data = $request->validate([
            'department_ids'   => ['present', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);

        $ids = $data['department_ids'];

        DB::transaction(function () use ($workProfile, $ids) {
            // ปลดแผนกที่เคยผูกกับโปรไฟล์นี้ออกก่อน
            Department::where('work_profile_id', $workProfile->id)
                ->whereNotIn('id', $ids ?: [0])
                ->update(['work_profile_id' => null]);

            // ผูกแผนกที่เลือก
            if (! empty($ids)) {
                Department::whereIn('id', $ids)->update(['work_profile_id' => $workProfile->id]);
            }
        });

        return response()->json([
            'data' => $workProfile->load(['workShift', 'departments:id,code,name,work_profile_id']),
        ]);
    }

    private function ensureSingleDefault(WorkProfile $profile): void
    {
        if ($profile->is_default) {
            WorkProfile::where('id', '!=', $profile->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'work_shift_id' => ['nullable', 'exists:work_shifts,id'],
            'work_days'     => ['nullable', 'array'],
            'work_days.*'   => ['integer', 'between:1,7'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'is_default'    => ['boolean'],
            'is_active'     => ['boolean'],
        ]);
    }
}

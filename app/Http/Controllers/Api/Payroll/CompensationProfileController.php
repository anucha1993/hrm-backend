<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CompensationProfile;
use App\Models\ProfileRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class CompensationProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CompensationProfile::with('rules')->orderByDesc('is_default')->orderBy('name');
        if ($s = $request->string('search')->toString()) {
            $q->where('name', 'like', "%{$s}%");
        }
        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }
        return response()->json(['data' => $q->get()]);
    }

    public function show(CompensationProfile $profile): JsonResponse
    {
        return response()->json(['data' => $profile->load('rules')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        return DB::transaction(function () use ($data) {
            if (! empty($data['is_default'])) {
                CompensationProfile::query()->update(['is_default' => false]);
            }
            $profile = CompensationProfile::create($data);
            $this->syncRules($profile, $data['rules'] ?? []);
            return response()->json(['data' => $profile->load('rules')], 201);
        });
    }

    public function update(Request $request, CompensationProfile $profile): JsonResponse
    {
        $data = $this->validateData($request, $profile);
        return DB::transaction(function () use ($data, $profile) {
            if (! empty($data['is_default'])) {
                CompensationProfile::where('id', '!=', $profile->id)->update(['is_default' => false]);
            }
            $profile->update($data);
            if (array_key_exists('rules', $data)) {
                $this->syncRules($profile, $data['rules'] ?? []);
            }
            return response()->json(['data' => $profile->fresh('rules')]);
        });
    }

    public function destroy(CompensationProfile $profile): JsonResponse
    {
        if ($profile->employeeCompensations()->exists()) {
            return response()->json(['message' => 'มีพนักงานใช้โปรไฟล์นี้อยู่ ลบไม่ได้'], 422);
        }
        $profile->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function validateData(Request $request, ?CompensationProfile $profile = null): array
    {
        $rules = [
            'name' => [$profile ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'pay_frequency' => ['sometimes', Rule::in(['monthly', 'biweekly', 'weekly', 'daily'])],
            'working_days_per_period' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'working_hours_per_day' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'ot_rate_normal' => ['sometimes', 'numeric', 'min:0'],
            'ot_rate_holiday' => ['sometimes', 'numeric', 'min:0'],
            'ot_rate_holiday_overtime' => ['sometimes', 'numeric', 'min:0'],
            'late_deduction_method' => ['sometimes', Rule::in(['none', 'per_minute', 'per_hour', 'per_incident', 'fixed'])],
            'late_deduction_rate' => ['sometimes', 'numeric', 'min:0'],
            'late_grace_minutes' => ['sometimes', 'integer', 'min:0'],
            'absent_deduction_method' => ['sometimes', Rule::in(['none', 'daily_wage', 'fixed'])],
            'absent_deduction_amount' => ['sometimes', 'numeric', 'min:0'],
            'ssf_enabled' => ['sometimes', 'boolean'],
            'ssf_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ssf_min_base' => ['sometimes', 'numeric', 'min:0'],
            'ssf_max_base' => ['sometimes', 'numeric', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'rules' => ['sometimes', 'array'],
            'rules.*.id' => ['sometimes', 'integer'],
            'rules.*.name' => ['required_with:rules', 'string', 'max:255'],
            'rules.*.trigger' => ['required_with:rules', Rule::in(['absent_count', 'late_count', 'late_minutes_total', 'present_days', 'continuous_present_days', 'ot_hours_total'])],
            'rules.*.operator' => ['required_with:rules', Rule::in(['eq', 'lte', 'gte', 'lt', 'gt', 'between'])],
            'rules.*.threshold' => ['required_with:rules', 'numeric'],
            'rules.*.threshold_max' => ['nullable', 'numeric'],
            'rules.*.action' => ['required_with:rules', Rule::in(['add_bonus', 'add_deduction', 'add_allowance'])],
            'rules.*.amount_type' => ['sometimes', Rule::in(['fixed', 'percent_of_base'])],
            'rules.*.amount' => ['required_with:rules', 'numeric'],
            'rules.*.scope' => ['sometimes', Rule::in(['this_period', 'year_to_date'])],
            'rules.*.taxable' => ['sometimes', 'boolean'],
            'rules.*.affects_ssf' => ['sometimes', 'boolean'],
            'rules.*.priority' => ['sometimes', 'integer'],
            'rules.*.is_active' => ['sometimes', 'boolean'],
        ];
        return $request->validate($rules);
    }

    protected function syncRules(CompensationProfile $profile, array $rules): void
    {
        $keepIds = [];
        foreach ($rules as $r) {
            $payload = collect($r)->only([
                'name', 'trigger', 'operator', 'threshold', 'threshold_max',
                'action', 'amount_type', 'amount', 'scope',
                'taxable', 'affects_ssf', 'priority', 'is_active',
            ])->toArray();
            $payload['compensation_profile_id'] = $profile->id;
            if (! empty($r['id'])) {
                ProfileRule::where('id', $r['id'])->where('compensation_profile_id', $profile->id)->update($payload);
                $keepIds[] = $r['id'];
            } else {
                $created = ProfileRule::create($payload);
                $keepIds[] = $created->id;
            }
        }
        ProfileRule::where('compensation_profile_id', $profile->id)
            ->whereNotIn('id', $keepIds)->delete();
    }
}

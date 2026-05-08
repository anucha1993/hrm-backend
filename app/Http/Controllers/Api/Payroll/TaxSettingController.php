<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\TaxBracket;
use App\Models\TaxProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxSettingController extends Controller
{
    /* ----- brackets ----- */
    public function brackets(Request $request): JsonResponse
    {
        $q = TaxBracket::orderBy('order')->orderBy('min_income');
        if ($y = $request->integer('year')) {
            $q->where('effective_year', $y);
        }
        return response()->json(['data' => $q->get()]);
    }

    public function syncBrackets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'effective_year' => ['nullable', 'integer'],
            'brackets' => ['required', 'array', 'min:1'],
            'brackets.*.id' => ['nullable', 'integer'],
            'brackets.*.min_income' => ['required', 'numeric', 'min:0'],
            'brackets.*.max_income' => ['nullable', 'numeric', 'min:0'],
            'brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'brackets.*.order' => ['sometimes', 'integer'],
            'brackets.*.is_active' => ['sometimes', 'boolean'],
        ]);
        $year = $data['effective_year'] ?? null;
        $keep = [];
        foreach ($data['brackets'] as $i => $row) {
            $payload = collect($row)->only(['min_income', 'max_income', 'rate', 'is_active'])->toArray();
            $payload['order'] = $row['order'] ?? $i;
            $payload['effective_year'] = $year;
            if (! empty($row['id'])) {
                TaxBracket::where('id', $row['id'])->update($payload);
                $keep[] = $row['id'];
            } else {
                $keep[] = TaxBracket::create($payload)->id;
            }
        }
        TaxBracket::when($year, fn ($q) => $q->where('effective_year', $year))
            ->whereNotIn('id', $keep)->delete();
        return response()->json(['data' => TaxBracket::orderBy('order')->get()]);
    }

    /* ----- tax profiles ----- */
    public function profiles(): JsonResponse
    {
        return response()->json(['data' => TaxProfile::orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function showProfile(TaxProfile $taxProfile): JsonResponse
    {
        return response()->json(['data' => $taxProfile]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $data = $request->validate($this->profileRules());
        if (! empty($data['is_default'])) {
            TaxProfile::query()->update(['is_default' => false]);
        }
        return response()->json(['data' => TaxProfile::create($data)], 201);
    }

    public function updateProfile(Request $request, TaxProfile $taxProfile): JsonResponse
    {
        $data = $request->validate($this->profileRules($taxProfile));
        if (! empty($data['is_default'])) {
            TaxProfile::where('id', '!=', $taxProfile->id)->update(['is_default' => false]);
        }
        $taxProfile->update($data);
        return response()->json(['data' => $taxProfile->fresh()]);
    }

    public function destroyProfile(TaxProfile $taxProfile): JsonResponse
    {
        $taxProfile->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function profileRules(?TaxProfile $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        return [
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'personal_allowance' => ['sometimes', 'numeric', 'min:0'],
            'spouse_allowance' => ['sometimes', 'numeric', 'min:0'],
            'children_count' => ['sometimes', 'integer', 'min:0'],
            'child_allowance_each' => ['sometimes', 'numeric', 'min:0'],
            'parent_allowance' => ['sometimes', 'numeric', 'min:0'],
            'disabled_allowance' => ['sometimes', 'numeric', 'min:0'],
            'life_insurance' => ['sometimes', 'numeric', 'min:0'],
            'health_insurance' => ['sometimes', 'numeric', 'min:0'],
            'provident_fund' => ['sometimes', 'numeric', 'min:0'],
            'rmf_amount' => ['sometimes', 'numeric', 'min:0'],
            'ssf_amount' => ['sometimes', 'numeric', 'min:0'],
            'home_loan_interest' => ['sometimes', 'numeric', 'min:0'],
            'donation_amount' => ['sometimes', 'numeric', 'min:0'],
            'extra_deductions' => ['nullable', 'array'],
            'extra_deductions.*.name' => ['required_with:extra_deductions', 'string'],
            'extra_deductions.*.amount' => ['required_with:extra_deductions', 'numeric', 'min:0'],
            'expense_deduction_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'expense_deduction_max' => ['sometimes', 'numeric', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

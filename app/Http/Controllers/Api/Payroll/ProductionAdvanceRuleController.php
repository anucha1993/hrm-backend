<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\ProductionAdvanceRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionAdvanceRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ProductionAdvanceRule::query()
            ->with(['department:id,code,name', 'productionRateItems:id,code,name,category,work_type,unit'])
            ->orderBy('id');
        if ($request->boolean('only_active')) {
            $q->where('is_active', true);
        }
        return response()->json(['data' => $q->get()]);
    }

    public function show(ProductionAdvanceRule $rule): JsonResponse
    {
        return response()->json(['data' => $rule->load(['department:id,code,name', 'productionRateItems'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $rule = DB::transaction(function () use ($data, $request) {
            $rule = ProductionAdvanceRule::create([
                ...collect($data)->except('item_ids')->all(),
                'created_by' => $request->user()->id,
            ]);
            $rule->productionRateItems()->sync($data['item_ids'] ?? []);
            return $rule;
        });
        return response()->json(['data' => $rule->load(['department:id,code,name', 'productionRateItems'])], 201);
    }

    public function update(Request $request, ProductionAdvanceRule $rule): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        DB::transaction(function () use ($data, $rule) {
            $rule->update(collect($data)->except('item_ids')->all());
            if (array_key_exists('item_ids', $data)) {
                $rule->productionRateItems()->sync($data['item_ids']);
            }
        });
        return response()->json(['data' => $rule->fresh(['department:id,code,name', 'productionRateItems'])]);
    }

    public function destroy(ProductionAdvanceRule $rule): JsonResponse
    {
        $rule->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function rules(bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';
        return [
            'name' => [$req, 'string', 'max:200'],
            'unit' => [$req, 'string', 'max:30'],
            'target_qty' => [$req, 'numeric', 'min:0.01'],
            'scope' => [$req, Rule::in(['company', 'department'])],
            'department_id' => ['nullable', 'required_if:scope,department', 'exists:departments,id'],
            'metric_type' => ['nullable', Rule::in(['production_qty', 'attendance_days'])],
            'applies_to_department_ids' => ['nullable', 'array'],
            'applies_to_department_ids.*' => ['integer', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
            'item_ids' => [
                $req === 'required' ? 'required_if:metric_type,production_qty' : 'sometimes',
                'array',
            ],
            'item_ids.*' => ['integer', 'exists:production_rate_items,id'],
        ];
    }
}

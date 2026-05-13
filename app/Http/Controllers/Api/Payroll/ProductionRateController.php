<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\ProductionRateItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ProductionRateItem::query()
            ->orderBy('sort_order')
            ->orderBy('id');
        if ($request->boolean('only_active')) {
            $q->where('is_active', true);
        }
        if ($cat = $request->string('category')->toString()) {
            $q->where('category', $cat);
        }
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                   ->orWhere('code', 'like', "%{$search}%");
            });
        }
        return response()->json(['data' => $q->get()]);
    }

    public function show(ProductionRateItem $item): JsonResponse
    {
        return response()->json(['data' => $item]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $item = ProductionRateItem::create($data);
        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, ProductionRateItem $item): JsonResponse
    {
        $data = $request->validate($this->rules(true, $item->id));
        $item->update($data);
        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(ProductionRateItem $item): JsonResponse
    {
        $item->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function rules(bool $update = false, ?int $id = null): array
    {
        $req = $update ? 'sometimes' : 'required';
        return [
            'code' => [$req, 'string', 'max:50', Rule::unique('production_rate_items', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => [$req, 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:50'],
            'work_type' => [$req, Rule::in(['cast', 'lift', 'cast_lift', 'flat'])],
            'unit' => [$req, Rule::in(['raft', 'meter'])],
            'target_qty' => ['nullable', 'numeric', 'min:0'],
            'rate_at_target' => [$req, 'numeric', 'min:0'],
            'rate_below_target' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

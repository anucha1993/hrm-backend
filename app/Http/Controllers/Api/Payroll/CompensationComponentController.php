<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CompensationComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompensationComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CompensationComponent::orderBy('kind')->orderBy('name');
        if ($k = $request->string('kind')->toString()) {
            $q->where('kind', $k);
        }
        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        return response()->json(['data' => CompensationComponent::create($data)], 201);
    }

    public function show(CompensationComponent $component): JsonResponse
    {
        return response()->json(['data' => $component]);
    }

    public function update(Request $request, CompensationComponent $component): JsonResponse
    {
        $data = $request->validate($this->rules($component));
        $component->update($data);
        return response()->json(['data' => $component]);
    }

    public function destroy(CompensationComponent $component): JsonResponse
    {
        $component->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function rules(?CompensationComponent $existing = null): array
    {
        return [
            'code' => [$existing ? 'sometimes' : 'required', 'string', 'max:50',
                $existing ? Rule::unique('compensation_components', 'code')->ignore($existing->id) : 'unique:compensation_components,code'],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => [$existing ? 'sometimes' : 'required', Rule::in(['allowance', 'deduction'])],
            'default_amount' => ['sometimes', 'numeric', 'min:0'],
            'taxable' => ['sometimes', 'boolean'],
            'affects_ssf' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

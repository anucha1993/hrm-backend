<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeComponent;
use App\Models\EmployeeTaxSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeePayrollController extends Controller
{
    public function show(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => [
                'employee' => $employee,
                'compensations' => EmployeeCompensation::with('profile')
                    ->where('employee_id', $employee->id)
                    ->orderByDesc('effective_from')->get(),
                'components' => EmployeeComponent::with('component')
                    ->where('employee_id', $employee->id)->get(),
                'tax_setting' => EmployeeTaxSetting::with('taxProfile')
                    ->where('employee_id', $employee->id)->first(),
            ],
        ]);
    }

    /* ----- compensations ----- */
    public function storeCompensation(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'compensation_profile_id' => ['required', 'exists:compensation_profiles,id'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'hourly_rate_override' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['employee_id'] = $employee->id;
        return response()->json(['data' => EmployeeCompensation::create($data)], 201);
    }

    public function updateCompensation(Request $request, Employee $employee, EmployeeCompensation $compensation): JsonResponse
    {
        abort_unless($compensation->employee_id === $employee->id, 404);
        $data = $request->validate([
            'compensation_profile_id' => ['sometimes', 'exists:compensation_profiles,id'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'hourly_rate_override' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $compensation->update($data);
        return response()->json(['data' => $compensation->fresh('profile')]);
    }

    public function destroyCompensation(Employee $employee, EmployeeCompensation $compensation): JsonResponse
    {
        abort_unless($compensation->employee_id === $employee->id, 404);
        $compensation->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /* ----- components ----- */
    public function storeComponent(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'compensation_component_id' => ['required', 'exists:compensation_components,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'total_installments' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['employee_id'] = $employee->id;
        return response()->json(['data' => EmployeeComponent::create($data)->load('component')], 201);
    }

    public function updateComponent(Request $request, Employee $employee, EmployeeComponent $component): JsonResponse
    {
        abort_unless($component->employee_id === $employee->id, 404);
        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'total_installments' => ['nullable', 'integer', 'min:1'],
            'paid_installments' => ['sometimes', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $component->update($data);
        return response()->json(['data' => $component->fresh('component')]);
    }

    public function destroyComponent(Employee $employee, EmployeeComponent $component): JsonResponse
    {
        abort_unless($component->employee_id === $employee->id, 404);
        $component->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /* ----- tax setting ----- */
    public function upsertTaxSetting(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'tax_profile_id' => ['nullable', 'exists:tax_profiles,id'],
            'tax_method' => ['required', Rule::in(['progressive', 'fixed_rate', 'flat_amount', 'none'])],
            'fixed_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'flat_amount' => ['nullable', 'numeric', 'min:0'],
            'withhold_strategy' => ['sometimes', Rule::in(['annualize', 'per_period'])],
            'overrides' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $setting = EmployeeTaxSetting::updateOrCreate(
            ['employee_id' => $employee->id],
            $data,
        );
        return response()->json(['data' => $setting->load('taxProfile')]);
    }
}

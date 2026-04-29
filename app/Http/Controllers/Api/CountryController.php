<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CountryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Country::orderBy('name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'        => ['required', 'string', 'max:3', 'unique:countries,code'],
            'name'        => ['required', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'is_active'   => ['boolean'],
        ]);
        return response()->json(['data' => Country::create($data)], 201);
    }

    public function show(Country $country): JsonResponse
    {
        return response()->json(['data' => $country]);
    }

    public function update(Request $request, Country $country): JsonResponse
    {
        $data = $request->validate([
            'code'        => ['sometimes', 'string', 'max:3', Rule::unique('countries', 'code')->ignore($country->id)],
            'name'        => ['sometimes', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'is_active'   => ['boolean'],
        ]);
        $country->update($data);
        return response()->json(['data' => $country]);
    }

    public function destroy(Country $country): JsonResponse
    {
        if ($country->employees()->exists()) {
            return response()->json(['message' => 'มีพนักงานใช้ประเทศนี้อยู่'], 422);
        }
        $country->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}

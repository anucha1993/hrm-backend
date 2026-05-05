<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficeLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = OfficeLocation::query()->orderBy('name');
        if ($request->boolean('active_only')) $q->where('is_active', true);
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $location = OfficeLocation::create($data);
        return response()->json(['data' => $location], 201);
    }

    public function update(Request $request, OfficeLocation $officeLocation): JsonResponse
    {
        $data = $this->validateData($request);
        $officeLocation->update($data);
        return response()->json(['data' => $officeLocation]);
    }

    public function destroy(OfficeLocation $officeLocation): JsonResponse
    {
        $officeLocation->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'latitude'         => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'        => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m'         => ['nullable', 'integer', 'min:10', 'max:50000'],
            'enforce_geofence' => ['boolean'],
            'address'          => ['nullable', 'string', 'max:500'],
            'is_active'        => ['boolean'],
        ]);
    }
}

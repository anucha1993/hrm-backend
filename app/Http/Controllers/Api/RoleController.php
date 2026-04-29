<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Role::with('permissions')->withCount('users')->orderBy('id')->get(),
        ]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => Permission::orderBy('group')->orderBy('id')->get()->groupBy('group'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:100', 'unique:roles,name', 'regex:/^[a-z0-9_]+$/'],
            'display_name'   => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'permissions'    => ['array'],
            'permissions.*'  => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name'         => $data['name'],
            'display_name' => $data['display_name'],
            'description'  => $data['description'] ?? null,
            'is_system'    => false,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        return response()->json(['data' => $role->load('permissions')], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $role->load('permissions')]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id), 'regex:/^[a-z0-9_]+$/'],
            'display_name'  => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'permissions'   => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        // ห้ามเปลี่ยน "name" ของ role ระบบ
        if ($role->is_system && isset($data['name']) && $data['name'] !== $role->name) {
            return response()->json(['message' => 'ไม่สามารถเปลี่ยนชื่อ role ระบบได้'], 422);
        }

        $role->update(collect($data)->except('permissions')->all());

        // Super admin มีสิทธิ์ทั้งหมดเสมอ — ไม่ต้อง sync (logic เช็คใน hasPermission)
        if (! ($role->name === Role::SUPER_ADMIN) && array_key_exists('permissions', $data)) {
            $role->permissions()->sync($data['permissions']);
        }

        return response()->json(['data' => $role->load('permissions')]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'ไม่สามารถลบ role ระบบได้'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'ยังมีผู้ใช้สังกัด role นี้อยู่'], 422);
        }
        $role->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}

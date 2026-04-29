<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::with('role')->orderBy('id', 'desc');

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($roleId = $request->integer('role_id')) {
            $q->where('role_id', $roleId);
        }

        return response()->json([
            'data' => $q->paginate($request->integer('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', 'unique:users,email'],
            'password'  => ['required', 'string', 'min:6'],
            'role_id'   => ['required', 'exists:roles,id'],
            'is_active' => ['boolean'],
        ]);

        $user = User::create($data);

        return response()->json(['data' => $user->load('role')], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user->load('role.permissions')]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'email'     => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password'  => ['nullable', 'string', 'min:6'],
            'role_id'   => ['sometimes', 'exists:roles,id'],
            'is_active' => ['boolean'],
        ]);

        // ป้องกันลด role ของ super admin คนสุดท้าย
        if (
            $user->isSuperAdmin() &&
            isset($data['role_id']) &&
            $data['role_id'] != $user->role_id &&
            User::where('role_id', $user->role_id)->count() <= 1
        ) {
            return response()->json([
                'message' => 'ไม่สามารถลดสิทธิ์ Super Admin คนสุดท้ายได้',
            ], 422);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(['data' => $user->load('role')]);
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'ไม่สามารถลบบัญชีของตนเองได้'], 422);
        }

        if ($user->isSuperAdmin() && User::where('role_id', $user->role_id)->count() <= 1) {
            return response()->json(['message' => 'ไม่สามารถลบ Super Admin คนสุดท้ายได้'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}

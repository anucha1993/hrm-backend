<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($data['email']);

        /** @var User|null $user */
        $user = User::with('role.permissions')
            ->where('email', $identifier)
            ->first();

        // ถ้าไม่เจอจาก email ลองค้นจากรหัสพนักงาน (employee_code)
        if (! $user) {
            $employee = Employee::where('employee_code', $identifier)->first();
            if ($employee && $employee->user_id) {
                $user = User::with('role.permissions')->find($employee->user_id);
            }
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['บัญชีนี้ถูกระงับการใช้งาน'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role.permissions');
        return response()->json(['user' => $this->formatUser($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'ออกจากระบบเรียบร้อย']);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'is_active'   => $user->is_active,
            'role'        => $user->role ? [
                'id'           => $user->role->id,
                'name'         => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
            'permissions' => $user->getPermissionNames(),
        ];
    }
}

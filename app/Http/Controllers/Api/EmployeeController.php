<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmploymentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private const RELATIONS = ['department', 'country', 'employmentType', 'documents', 'user'];

    public function index(Request $request): JsonResponse
    {
        $q = Employee::with(self::RELATIONS)->orderBy('id', 'desc');

        if ($s = $request->string('search')->toString()) {
            $q->where(function ($w) use ($s) {
                $w->where('employee_code', 'like', "%{$s}%")
                  ->orWhere('first_name', 'like', "%{$s}%")
                  ->orWhere('last_name', 'like', "%{$s}%")
                  ->orWhere('nickname', 'like', "%{$s}%")
                  ->orWhere('national_id', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }
        if ($id = $request->integer('department_id'))      $q->where('department_id', $id);
        if ($id = $request->integer('employment_type_id')) $q->where('employment_type_id', $id);
        if ($status = $request->string('status')->toString()) $q->where('status', $status);

        // กรองสัญชาติ: ต่างด้าว = มี labour_id หรือระบุประเทศที่ไม่ใช่ไทย, คนไทย = ตรงข้าม
        $nationality = $request->string('nationality')->toString();
        if ($nationality === 'foreign' || $nationality === 'thai') {
            $thaiIds = Country::where('code', 'TH')->pluck('id')->all();

            if ($nationality === 'foreign') {
                $q->where(function ($w) use ($thaiIds) {
                    $w->whereNotNull('labour_id')
                      ->orWhere(function ($w2) use ($thaiIds) {
                          $w2->whereNotNull('country_id');
                          if ($thaiIds) $w2->whereNotIn('country_id', $thaiIds);
                      });
                });
            } else {
                $q->whereNull('labour_id')->where(function ($w) use ($thaiIds) {
                    $w->whereNull('country_id');
                    if ($thaiIds) $w->orWhereIn('country_id', $thaiIds);
                });
            }
        }

        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->load(self::RELATIONS)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        return DB::transaction(function () use ($request, $data) {
            $data = $this->ensureUserAccount($data, null);
            $employee = Employee::create($data);
            $this->saveDocuments($request, $employee);
            return response()->json(['data' => $employee->load(self::RELATIONS)], 201);
        });
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validateData($request, $employee->id);

        return DB::transaction(function () use ($request, $data, $employee) {
            $data = $this->ensureUserAccount($data, $employee);
            $employee->update($data);

            // ลบเอกสารตาม id ที่ส่งมา
            $deleteIds = $request->input('delete_document_ids', []);
            if (is_array($deleteIds) && count($deleteIds) > 0) {
                $docs = $employee->documents()->whereIn('id', $deleteIds)->get();
                foreach ($docs as $doc) {
                    Storage::disk('public')->delete($doc->file_path);
                    $doc->delete();
                }
            }

            $this->saveDocuments($request, $employee);

            return response()->json(['data' => $employee->load(self::RELATIONS)]);
        });
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'employee_code'   => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_code')->ignore($id)],
            'title'           => ['required', Rule::in(['นาย', 'นางสาว', 'นาง'])],
            'first_name'      => ['required', 'string', 'max:255'],
            'last_name'       => ['required', 'string', 'max:255'],
            'nickname'        => ['nullable', 'string', 'max:255'],
            'birth_date'      => ['required', 'date', 'before:today'],
            'gender'          => ['required', Rule::in(['M', 'F', 'Other'])],
            'phone'           => ['nullable', 'string', 'max:15'],
            'email'           => ['nullable', 'email', Rule::unique('employees', 'email')->ignore($id)],

            'user_id'         => ['nullable', 'integer', Rule::unique('employees', 'user_id')->ignore($id), 'exists:users,id'],
            'address'         => ['nullable', 'string'],
            'national_id'     => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('employees', 'national_id')->ignore($id)],
            'hip_enroll_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'hip_enroll_number')->ignore($id)],
            'marital_status'  => ['nullable', 'string', 'max:50'],
            'religion'        => ['nullable', 'string', 'max:50'],
            'education_level' => ['nullable', 'string', 'max:100'],

            'country_id'         => ['nullable', 'exists:countries,id'],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'work_profile_id'    => ['nullable', 'exists:work_profiles,id'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'],

            'position'    => ['nullable', 'string', 'max:255'],
            'hire_date'   => ['nullable', 'date'],
            'resign_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],

            'bank_name'         => ['nullable', 'string', 'max:255'],
            'bank_account_no'   => ['nullable', 'string', 'max:30'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],

            'emergency_contact_name'     => ['nullable', 'string', 'max:255'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone'    => ['nullable', 'string', 'max:15'],

            'status' => ['required', Rule::in(['active', 'resigned', 'terminated', 'suspended'])],
            'note'   => ['nullable', 'string'],

            'documents'   => ['nullable', 'array'],
            'documents.*' => ['file', 'max:10240'], // 10MB

            'delete_document_ids'   => ['nullable', 'array'],
            'delete_document_ids.*' => ['integer'],
        ]);

        // จ้างตามชิ้นงาน (งานเหมา) จ่ายตามเรทค่าจ้างการผลิต → ไม่มีเงินเดือน/ค่าจ้างประจำ
        if (! empty($data['employment_type_id'])
            && EmploymentType::whereKey($data['employment_type_id'])->where('code', 'PIECEWORK')->exists()) {
            $data['base_salary'] = null;
        }

        return $data;
    }

    /**
     * สร้าง/อัปเดตบัญชี User ของพนักงานแบบอัตโนมัติ
     * - Username (email) = "{employee_code}@cyc-hrm.local"
     * - Password         = national_id (เลข ปปช./พาสปอร์ต)
     * - Role             = employee
     *
     * เมื่อแก้ไข: ถ้า employee_code หรือ national_id เปลี่ยน จะ sync ไปที่บัญชี User ที่ผูกอยู่
     */
    private function ensureUserAccount(array $data, ?Employee $employee): array
    {
        $employeeCode = $data['employee_code'] ?? $employee?->employee_code;
        $nationalId   = $data['national_id']   ?? $employee?->national_id;

        if (! $employeeCode || ! $nationalId) {
            return $data;
        }

        $employeeRole = Role::where('name', Role::EMPLOYEE)->first();
        if (! $employeeRole) {
            return $data; // ไม่มี role employee ในระบบ → ข้าม
        }

        $syntheticEmail = strtolower($employeeCode) . '@cyc-hrm.local';
        $fullName = trim(($data['first_name'] ?? $employee?->first_name ?? '') . ' ' . ($data['last_name'] ?? $employee?->last_name ?? ''));
        $existingUserId = $employee?->user_id ?? ($data['user_id'] ?? null);

        if ($existingUserId && $user = User::find($existingUserId)) {
            // sync ข้อมูลถ้าเปลี่ยน
            $update = [
                'name'    => $fullName ?: $user->name,
                'email'   => $syntheticEmail,
                'role_id' => $user->role_id ?: $employeeRole->id,
            ];
            // ถ้า national_id เปลี่ยน → อัปเดต password
            if ($employee && isset($data['national_id']) && $data['national_id'] !== $employee->national_id) {
                $update['password'] = $nationalId;
            }
            $user->fill($update)->save();
            $data['user_id'] = $user->id;
            return $data;
        }

        // ถ้า email สังเคราะห์นี้ถูกใช้ไปแล้วโดย user อื่น (เช่น สร้างซ้ำ) → ผูกกับ user เดิม
        $existing = User::where('email', $syntheticEmail)->first();
        if ($existing) {
            $data['user_id'] = $existing->id;
            return $data;
        }

        $user = User::create([
            'name'      => $fullName ?: $employeeCode,
            'email'     => $syntheticEmail,
            'password'  => $nationalId, // hashed อัตโนมัติผ่าน cast 'hashed' ใน User model
            'role_id'   => $employeeRole->id,
            'is_active' => true,
        ]);

        $data['user_id'] = $user->id;
        return $data;
    }

    private function saveDocuments(Request $request, Employee $employee): void
    {
        if (! $request->hasFile('documents')) return;

        foreach ($request->file('documents') as $file) {
            if (! $file || ! $file->isValid()) continue;
            $path = $file->store("employees/{$employee->id}/documents", 'public');
            $employee->documents()->create([
                'name'          => $file->getClientOriginalName(),
                'file_path'     => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
            ]);
        }
    }
}

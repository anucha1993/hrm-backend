<?php

namespace App\Http\Controllers\Api;

use App\Exports\EmployeeImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeImportController extends Controller
{
    /**
     * GET /api/employees/import/template
     * ดาวน์โหลดไฟล์ template (.xlsx) สำหรับ import
     */
    public function template()
    {
        $filename = 'employee_import_template_' . date('Ymd') . '.xlsx';
        return Excel::download(new EmployeeImportTemplateExport(), $filename);
    }

    /**
     * POST /api/employees/import
     * อัปโหลดไฟล์ .xlsx/.csv เพื่อ import ข้อมูลพนักงาน
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        // อ่านเป็น array (heading row = แถวแรก)
        $rows = Excel::toArray(null, $request->file('file'))[0] ?? [];

        if (count($rows) < 2) {
            return response()->json(['message' => 'ไฟล์ว่างเปล่า หรือไม่มีข้อมูล'], 422);
        }

        $headers = array_map(
            fn ($h) => is_string($h) ? trim($h) : $h,
            $rows[0]
        );
        $dataRows = array_slice($rows, 1);

        // map รหัส → id (cache ไว้ใน memory)
        $countryMap        = Country::pluck('id', 'code')->all();
        $departmentMap     = Department::pluck('id', 'code')->all();
        $employmentTypeMap = EmploymentType::pluck('id', 'code')->all();

        $employeeRole = Role::where('name', Role::EMPLOYEE)->first();

        $created = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($dataRows as $i => $row) {
            $rowNum = $i + 2; // +1 = header, +1 = 1-indexed

            // ข้ามแถวว่างทั้งหมด
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $idx => $header) {
                if (! $header) continue;
                $val = $row[$idx] ?? null;
                if (is_string($val)) $val = trim($val);
                $assoc[$header] = $val === '' ? null : $val;
            }

            try {
                $payload = $this->buildPayload($assoc, $countryMap, $departmentMap, $employmentTypeMap);

                $validator = Validator::make($payload, $this->rules());
                if ($validator->fails()) {
                    $errors[] = [
                        'row'    => $rowNum,
                        'code'   => $assoc['employee_code'] ?? '',
                        'errors' => $validator->errors()->all(),
                    ];
                    $skipped++;
                    continue;
                }

                DB::transaction(function () use ($payload, $employeeRole) {
                    $payload = $this->ensureUserAccount($payload, $employeeRole);
                    Employee::create($payload);
                });

                $created++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row'    => $rowNum,
                    'code'   => $assoc['employee_code'] ?? '',
                    'errors' => [$e->getMessage()],
                ];
                $skipped++;
            }
        }

        return response()->json([
            'message' => "นำเข้าสำเร็จ {$created} รายการ, ข้าม {$skipped} รายการ",
            'summary' => [
                'created' => $created,
                'skipped' => $skipped,
                'total'   => $created + $skipped,
            ],
            'errors' => $errors,
        ]);
    }

    private function buildPayload(array $row, array $countryMap, array $deptMap, array $empTypeMap): array
    {
        $countryId      = isset($row['country_code']) ? ($countryMap[$row['country_code']] ?? null) : null;
        $departmentId   = $this->resolveDepartmentId($row, $deptMap);
        $employmentType = isset($row['employment_type_code']) ? ($empTypeMap[$row['employment_type_code']] ?? null) : null;

        // วันเกิด: แปลงค่า ถ้าได้วันที่อนาคต (ข้อมูลผิด) ให้เป็น null แทนการตกข้ามทั้งแถว
        $birthDate = $this->parseDate($row['birth_date'] ?? null);
        if ($birthDate !== null && $birthDate >= now()->format('Y-m-d')) {
            $birthDate = null;
        }

        return [
            'employee_code'              => $row['employee_code'] ?? null,
            'title'                      => $row['title'] ?? null,
            'first_name'                 => $row['first_name'] ?? null,
            'last_name'                  => $row['last_name'] ?? '-',
            'nickname'                   => $row['nickname'] ?? null,
            'birth_date'                 => $birthDate,
            'gender'                     => $row['gender'] ?? null,
            'national_id'                => $row['national_id'] !== null ? (string) $row['national_id'] : null,
            'labour_id'                  => isset($row['labour_id']) && $row['labour_id'] !== '' ? (int) $row['labour_id'] : null,
            'phone'                      => $row['phone'] !== null ? (string) $row['phone'] : null,
            'email'                      => $row['email'] ?? null,
            'address'                    => $row['address'] ?? null,
            'marital_status'             => $row['marital_status'] ?? null,
            'religion'                   => $row['religion'] ?? null,
            'education_level'            => $row['education_level'] ?? null,
            'country_id'                 => $countryId,
            'department_id'              => $departmentId,
            'employment_type_id'         => $employmentType,
            'position'                   => $row['position'] ?? null,
            'hire_date'                  => $this->parseDate($row['hire_date'] ?? null),
            'resign_date'                => $this->parseDate($row['resign_date'] ?? null),
            'base_salary'                => $row['base_salary'] !== null && $row['base_salary'] !== '' ? (float) $row['base_salary'] : null,
            'bank_name'                  => $row['bank_name'] ?? null,
            'bank_account_no'            => $row['bank_account_no'] !== null ? (string) $row['bank_account_no'] : null,
            'bank_account_name'          => $row['bank_account_name'] ?? null,
            'emergency_contact_name'     => $row['emergency_contact_name'] ?? null,
            'emergency_contact_relation' => $row['emergency_contact_relation'] ?? null,
            'emergency_contact_phone'    => $row['emergency_contact_phone'] !== null ? (string) $row['emergency_contact_phone'] : null,
            'status'                     => $row['status'] ?? 'active',
            'note'                       => $row['note'] ?? null,
        ];
    }

    /**
     * หา department_id จาก department_code ก่อน ถ้าไม่ระบุ/ไม่พบ ให้เดาจาก prefix ของรหัสพนักงาน
     * (รหัสพนักงานตั้งตามรหัสแผนก เช่น PSY001 -> แผนก PSY, OFF001 -> แผนก OFF)
     */
    private function resolveDepartmentId(array $row, array $deptMap): ?int
    {
        $code = $row['department_code'] ?? null;
        if ($code !== null && isset($deptMap[$code])) {
            return $deptMap[$code];
        }

        $empCode = strtoupper(trim((string) ($row['employee_code'] ?? '')));
        if ($empCode === '') {
            return null;
        }

        // จับคู่รหัสแผนกที่ยาวที่สุดก่อน เพื่อเลี่ยงการ match บางส่วน
        $deptCodes = array_keys($deptMap);
        usort($deptCodes, fn ($a, $b) => strlen((string) $b) - strlen((string) $a));

        foreach ($deptCodes as $dc) {
            $dcUpper = strtoupper((string) $dc);
            if ($dcUpper !== '' && str_starts_with($empCode, $dcUpper)) {
                $rest = substr($empCode, strlen($dcUpper));
                if ($rest === '' || ctype_digit($rest[0])) {
                    return $deptMap[$dc];
                }
            }
        }

        return null;
    }

    private function rules(): array
    {
        return [
            'employee_code'      => ['required', 'string', 'max:50', 'unique:employees,employee_code'],
            'title'              => ['required', \Illuminate\Validation\Rule::in(['นาย', 'นางสาว', 'นาง'])],
            'first_name'         => ['required', 'string', 'max:255'],
            'last_name'          => ['required', 'string', 'max:255'],
            'birth_date'         => ['nullable', 'date', 'before:today'],
            'gender'             => ['required', \Illuminate\Validation\Rule::in(['M', 'F', 'Other'])],
            'national_id'        => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/', 'unique:employees,national_id'],
            'labour_id'          => ['nullable', 'integer', 'min:1'],
            'email'              => ['nullable', 'email', 'unique:employees,email'],
            'country_id'         => ['nullable', 'exists:countries,id'],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'],
            'hire_date'          => ['nullable', 'date'],
            'resign_date'        => ['nullable', 'date', 'after_or_equal:hire_date'],
            'base_salary'        => ['nullable', 'numeric', 'min:0'],
            'status'             => ['required', \Illuminate\Validation\Rule::in(['active', 'resigned', 'terminated', 'suspended'])],
        ];
    }

    /**
     * แปลงค่า cell เป็นวันที่ (รองรับ string, Excel serial number และปีพุทธศักราช)
     */
    private function parseDate($value): ?string
    {
        if ($value === null || $value === '') return null;

        $carbon = null;

        if (is_numeric($value)) {
            try {
                $carbon = Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                );
            } catch (\Throwable $e) {
                $carbon = null;
            }
        }

        if ($carbon === null) {
            try {
                $carbon = Carbon::parse($value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        // แปลงปีพุทธศักราช (พ.ศ.) เป็นคริสต์ศักราช (ค.ศ.) อัตโนมัติ
        // ปี พ.ศ. จะมากกว่า 2400 เสมอ ส่วนปี ค.ศ. ของวันเกิด/วันเริ่มงานจะน้อยกว่ามาก
        if ($carbon->year >= 2400) {
            $carbon = $carbon->copy()->subYears(543);
        }

        return $carbon->format('Y-m-d');
    }

    /**
     * สร้างบัญชี User สำหรับพนักงานที่ import (เหมือน EmployeeController::ensureUserAccount)
     */
    private function ensureUserAccount(array $data, ?Role $employeeRole): array
    {
        $employeeCode = $data['employee_code'] ?? null;
        $nationalId   = $data['national_id']   ?? null;

        if (! $employeeCode || ! $nationalId || ! $employeeRole) {
            return $data;
        }

        $syntheticEmail = strtolower($employeeCode) . '@cyc-hrm.local';
        $fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        $existing = User::where('email', $syntheticEmail)->first();
        if ($existing) {
            $data['user_id'] = $existing->id;
            return $data;
        }

        $user = User::create([
            'name'      => $fullName ?: $employeeCode,
            'email'     => $syntheticEmail,
            'password'  => $nationalId, // hashed อัตโนมัติผ่าน cast
            'role_id'   => $employeeRole->id,
            'is_active' => true,
        ]);

        $data['user_id'] = $user->id;
        return $data;
    }
}

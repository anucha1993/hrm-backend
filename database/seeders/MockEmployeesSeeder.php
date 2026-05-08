<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\EmploymentType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestLog;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\OtSession;
use App\Models\OtSessionEmployee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MockEmployeesSeeder extends Seeder
{
    public function run(): void
    {
        // 1) สถานที่ทำงาน + กะ (สร้างถ้าไม่มี)
        $office = OfficeLocation::firstOrCreate(
            ['name' => 'สำนักงานใหญ่'],
            [
                'latitude'         => 13.7563,
                'longitude'        => 100.5018,
                'radius_m'         => 200,
                'enforce_geofence' => false,
                'address'          => 'กรุงเทพมหานคร',
                'is_active'        => true,
            ]
        );

        $shift = WorkShift::firstOrCreate(
            ['name' => 'กะปกติ 08:00-17:00'],
            [
                'start_time'         => '08:00:00',
                'end_time'           => '17:00:00',
                'break_minutes'      => 60,
                'late_grace_minutes' => 15,
                'cross_midnight'     => false,
                'is_active'          => true,
            ]
        );

        // 2) Master data references
        $countryTH       = Country::where('code', 'TH')->first();
        $employmentType  = EmploymentType::where('code', 'MONTHLY')->first();
        $deptHR          = Department::where('code', 'HR')->first();
        $deptIT          = Department::where('code', 'IT')->first();
        $deptACC         = Department::where('code', 'ACC')->first();
        $deptOPS         = Department::where('code', 'OPS')->first();
        $deptSALE        = Department::where('code', 'SALE')->first();

        $roleEmployee = Role::where('name', Role::EMPLOYEE)->first();
        $roleManager  = Role::where('name', Role::MANAGER)->first();
        $roleHr       = Role::where('name', Role::HR)->first();

        // เก็บ employees ที่สร้างไว้ใช้ทำ OT/ลา
        $employees = [];

        // 3) ข้อมูลพนักงาน Mockup 10 คน
        $mocks = [
            ['code' => 'EMP001', 'title' => 'นาย', 'first' => 'สมชาย', 'last' => 'ใจดี',      'nick' => 'ชาย',  'gender' => 'M',   'salary' => 35000, 'dept' => $deptIT,   'pos' => 'Senior Developer',   'role' => $roleEmployee],
            ['code' => 'EMP002', 'title' => 'นางสาว', 'first' => 'สมหญิง', 'last' => 'รักงาน',  'nick' => 'หญิง', 'gender' => 'F', 'salary' => 28000, 'dept' => $deptHR,   'pos' => 'HR Officer',         'role' => $roleHr],
            ['code' => 'EMP003', 'title' => 'นาย', 'first' => 'มานพ',   'last' => 'พากเพียร', 'nick' => 'นพ',   'gender' => 'M',   'salary' => 45000, 'dept' => $deptIT,   'pos' => 'Tech Lead',          'role' => $roleManager],
            ['code' => 'EMP004', 'title' => 'นางสาว', 'first' => 'อรพิน', 'last' => 'มงคลชัย', 'nick' => 'พิน',  'gender' => 'F', 'salary' => 26000, 'dept' => $deptACC,  'pos' => 'Accountant',         'role' => $roleEmployee],
            ['code' => 'EMP005', 'title' => 'นาย', 'first' => 'ปรีชา',  'last' => 'สุขใจ',    'nick' => 'ชา',   'gender' => 'M',   'salary' => 22000, 'dept' => $deptOPS,  'pos' => 'Operations Staff',   'role' => $roleEmployee],
            ['code' => 'EMP006', 'title' => 'นางสาว', 'first' => 'พรทิพย์','last' => 'ศรีสุข',  'nick' => 'ทิพย์','gender' => 'F', 'salary' => 32000, 'dept' => $deptSALE, 'pos' => 'Senior Sales',       'role' => $roleEmployee],
            ['code' => 'EMP007', 'title' => 'นาย', 'first' => 'วรวุฒิ',  'last' => 'ทองดี',    'nick' => 'วุฒิ', 'gender' => 'M',   'salary' => 24000, 'dept' => $deptOPS,  'pos' => 'Logistics',          'role' => $roleEmployee],
            ['code' => 'EMP008', 'title' => 'นางสาว', 'first' => 'ศิริวรรณ','last' => 'จันทร์เพ็ญ','nick' => 'วรรณ','gender' => 'F','salary' => 30000, 'dept' => $deptACC,  'pos' => 'Senior Accountant',  'role' => $roleEmployee],
            ['code' => 'EMP009', 'title' => 'นาย', 'first' => 'ธนกฤต',  'last' => 'รุ่งเรือง', 'nick' => 'กฤต', 'gender' => 'M',    'salary' => 38000, 'dept' => $deptSALE, 'pos' => 'Sales Manager',      'role' => $roleManager],
            ['code' => 'EMP010', 'title' => 'นางสาว', 'first' => 'ณัฐริกา','last' => 'พิมพ์ใจ','nick' => 'ริกา', 'gender' => 'F', 'salary' => 25000, 'dept' => $deptIT,   'pos' => 'Junior Developer',   'role' => $roleEmployee],
        ];

        foreach ($mocks as $m) {
            $email = strtolower($m['code']) . '@cyc-hrm.local';

            // สร้าง User (ใส่รหัสผ่าน password ทั้งหมด)
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'      => "{$m['first']} {$m['last']}",
                    'password'  => 'password',
                    'role_id'   => $m['role']->id,
                    'is_active' => true,
                ]
            );

            // สร้าง Employee
            $employee = Employee::updateOrCreate(
                ['employee_code' => $m['code']],
                [
                    'title'              => $m['title'],
                    'first_name'         => $m['first'],
                    'last_name'          => $m['last'],
                    'nickname'           => $m['nick'],
                    'birth_date'         => Carbon::now()->subYears(rand(24, 45))->subDays(rand(0, 360))->toDateString(),
                    'gender'             => $m['gender'],
                    'phone'              => '08' . rand(10000000, 99999999),
                    'email'              => $email,
                    'national_id'        => (string) rand(1000000000000, 9999999999999),
                    'marital_status'     => ['single', 'married'][rand(0, 1)],
                    'religion'           => 'พุทธ',
                    'country_id'         => $countryTH?->id,
                    'department_id'      => $m['dept']?->id,
                    'employment_type_id' => $employmentType?->id,
                    'position'           => $m['pos'],
                    'hire_date'          => Carbon::now()->subMonths(rand(6, 48))->toDateString(),
                    'base_salary'        => $m['salary'],
                    'bank_name'          => 'ธนาคารกสิกรไทย',
                    'bank_account_no'    => (string) rand(1000000000, 9999999999),
                    'bank_account_name'  => "{$m['first']} {$m['last']}",
                    'status'             => Employee::STATUS_ACTIVE,
                    'user_id'            => $user->id,
                ]
            );

            // กะการทำงาน
            EmployeeShift::updateOrCreate(
                ['employee_id' => $employee->id, 'work_shift_id' => $shift->id],
                [
                    'effective_from' => Carbon::now()->subYear()->toDateString(),
                    'effective_to'   => null,
                    'work_days'      => [1, 2, 3, 4, 5], // จ-ศ
                ]
            );

            // ข้อมูลลงเวลา 30 วันย้อนหลัง
            $this->seedAttendance($employee, $shift, $office);

            $employees[] = $employee;
        }

        // 4) Mock OT sessions + ผู้เข้าร่วม
        $hrUser = User::whereHas('role', fn ($q) => $q->where('name', Role::HR))->first();
        $this->seedOtSessions($employees, $hrUser);

        // 5) Mock Leave Balances + Requests
        $this->seedLeaveData($employees, $hrUser);

        $this->command?->info('สร้างพนักงาน 10 คน + ลงเวลา + OT + ลา สำเร็จ');
        $this->command?->info('Login ทดสอบ: emp001@cyc-hrm.local ถึง emp010@cyc-hrm.local / password = password');
    }

    private function seedAttendance(Employee $employee, WorkShift $shift, OfficeLocation $office): void
    {
        // ลบของเก่าใน 30 วันก่อน เพื่อ run ซ้ำได้
        Attendance::where('employee_id', $employee->id)
            ->where('checked_at', '>=', Carbon::now()->subDays(30)->startOfDay())
            ->delete();

        for ($i = 30; $i >= 1; $i--) {
            $date = Carbon::now()->subDays($i)->startOfDay();
            // ข้ามเสาร์-อาทิตย์
            if ($date->isWeekend()) continue;

            // 5% ขาด, 10% ลา (ข้าม), 15% สาย, ที่เหลือมาตรงเวลา
            $rand = rand(1, 100);
            if ($rand <= 5) continue;          // ขาดงาน — ไม่บันทึกเลย
            if ($rand <= 15) continue;         // ลา — จะถูกอ่านจากตาราง leave_requests แทน

            $isLate    = $rand <= 30;
            $checkInH  = 8;
            $checkInM  = $isLate ? rand(20, 55) : rand(-15, 14); // -15..+14 = ตรงเวลา (ก่อน 8:15)
            $lateMin   = max(0, ($checkInH * 60 + $checkInM) - (8 * 60 + 15)); // เกิน grace 15 นาที

            $checkInAt = $date->copy()->setTime(8, 0)->addMinutes($checkInM);

            Attendance::create([
                'employee_id'        => $employee->id,
                'type'               => 'check_in',
                'checked_at'         => $checkInAt,
                'latitude'           => $office->latitude,
                'longitude'          => $office->longitude,
                'accuracy_m'         => rand(5, 20),
                'office_location_id' => $office->id,
                'distance_m'         => rand(0, 50),
                'outside_geofence'   => false,
                'work_shift_id'      => $shift->id,
                'status'             => $lateMin > 0 ? 'late' : 'normal',
                'late_minutes'       => $lateMin > 0 ? $lateMin : null,
            ]);

            // Check-out: 17:00 ± บางคนทำ OT
            $otMin = (rand(1, 100) <= 20) ? rand(30, 120) : rand(-10, 15);
            $checkOutAt = $date->copy()->setTime(17, 0)->addMinutes($otMin);

            Attendance::create([
                'employee_id'        => $employee->id,
                'type'               => 'check_out',
                'checked_at'         => $checkOutAt,
                'latitude'           => $office->latitude,
                'longitude'          => $office->longitude,
                'accuracy_m'         => rand(5, 20),
                'office_location_id' => $office->id,
                'distance_m'         => rand(0, 50),
                'outside_geofence'   => false,
                'work_shift_id'      => $shift->id,
                'status'             => $otMin >= 30 ? 'overtime' : 'normal',
                'late_minutes'       => null,
            ]);
        }
    }

    /**
     * สร้างรอบ OT 4 รอบ (วันธรรมดาเย็น 2 รอบ + เสาร์ 1 รอบ + วันหยุด 1 รอบ)
     */
    private function seedOtSessions(array $employees, ?User $hrUser): void
    {
        // ลบของเก่า 60 วันย้อนหลัง
        $cutoff = Carbon::now()->subDays(60)->toDateString();
        OtSessionEmployee::whereHas('session', fn ($q) => $q->where('ot_date', '>=', $cutoff))->delete();
        OtSession::where('ot_date', '>=', $cutoff)->delete();

        $sessions = [
            [
                'ot_date'       => Carbon::now()->subDays(20)->toDateString(),
                'start_time'    => '17:30:00',
                'end_time'      => '20:30:00',
                'ot_type'       => 'normal',
                'rate_mode'     => 'multiplier',
                'multiplier'    => 1.50,
                'hourly_amount' => 0,
                'description'   => 'OT ปิดงาน Sprint',
                'status'        => 'closed',
                'pick'          => [0, 2, 9],          // EMP001, EMP003, EMP010 (IT)
                'hours'         => 3.0,
            ],
            [
                'ot_date'       => Carbon::now()->subDays(10)->toDateString(),
                'start_time'    => '17:30:00',
                'end_time'      => '19:30:00',
                'ot_type'       => 'normal',
                'rate_mode'     => 'multiplier',
                'multiplier'    => 1.50,
                'hourly_amount' => 0,
                'description'   => 'OT จัดทำรายงานสิ้นเดือน',
                'status'        => 'closed',
                'pick'          => [3, 7],             // EMP004, EMP008 (ACC)
                'hours'         => 2.0,
            ],
            [
                'ot_date'       => Carbon::now()->subDays(5)->toDateString(),
                'start_time'    => '09:00:00',
                'end_time'      => '17:00:00',
                'ot_type'       => 'holiday',
                'rate_mode'     => 'multiplier',
                'multiplier'    => 2.00,
                'hourly_amount' => 0,
                'description'   => 'OT วันเสาร์ — รับสินค้าเข้าโกดัง',
                'status'        => 'closed',
                'pick'          => [4, 6],             // EMP005, EMP007 (OPS)
                'hours'         => 7.0,
            ],
            [
                'ot_date'       => Carbon::now()->subDays(2)->toDateString(),
                'start_time'    => '17:30:00',
                'end_time'      => '20:00:00',
                'ot_type'       => 'normal',
                'rate_mode'     => 'hourly_amount',
                'multiplier'    => 1.50,
                'hourly_amount' => 150,
                'description'   => 'OT ปิดยอดขาย',
                'status'        => 'open',
                'pick'          => [5, 8],             // EMP006, EMP009 (SALE)
                'hours'         => 2.5,
            ],
        ];

        foreach ($sessions as $s) {
            $session = OtSession::create([
                'ot_date'       => $s['ot_date'],
                'start_time'    => $s['start_time'],
                'end_time'      => $s['end_time'],
                'ot_type'       => $s['ot_type'],
                'rate_mode'     => $s['rate_mode'],
                'hourly_amount' => $s['hourly_amount'],
                'multiplier'    => $s['multiplier'],
                'description'   => $s['description'],
                'status'        => $s['status'],
                'created_by'    => $hrUser?->id,
            ]);

            foreach ($s['pick'] as $idx) {
                if (! isset($employees[$idx])) continue;
                $emp = $employees[$idx];

                // อัตรา/ชม. จาก base_salary หาร 30 วัน หาร 8 ชม. (โดยประมาณ)
                $hourlyBase = round(((float) $emp->base_salary / 30) / 8, 2);
                if ($s['rate_mode'] === 'multiplier') {
                    $rate = round($hourlyBase * (float) $s['multiplier'], 2);
                } else {
                    $rate = (float) $s['hourly_amount'] * (float) $s['multiplier'];
                }
                $total = round($rate * $s['hours'], 2);

                OtSessionEmployee::create([
                    'ot_session_id'        => $session->id,
                    'employee_id'          => $emp->id,
                    'hours'                => $s['hours'],
                    'hourly_rate_snapshot' => $rate,
                    'total_amount'         => $total,
                ]);
            }
        }
    }

    /**
     * สร้าง LeaveBalance ของปีปัจจุบัน + LeaveRequests หลายสถานะให้ดูครบ
     */
    private function seedLeaveData(array $employees, ?User $hrUser): void
    {
        $year = (int) Carbon::now()->year;
        $types = LeaveType::where('is_active', true)->get()->keyBy('code');

        if ($types->isEmpty()) return;

        // ลบ leave requests/logs/balances เดิมของปีนี้ เพื่อ re-run
        $empIds = collect($employees)->pluck('id')->all();
        LeaveRequestLog::whereIn(
            'leave_request_id',
            LeaveRequest::whereIn('employee_id', $empIds)->pluck('id')
        )->delete();
        LeaveRequest::whereIn('employee_id', $empIds)->forceDelete();
        LeaveBalance::whereIn('employee_id', $empIds)->where('year', $year)->delete();

        // 1) สร้าง LeaveBalance ทุกประเภทให้ทุกคน (ใช้ default_quota_days)
        foreach ($employees as $emp) {
            foreach ($types as $type) {
                LeaveBalance::create([
                    'employee_id'    => $emp->id,
                    'leave_type_id'  => $type->id,
                    'year'           => $year,
                    'quota_days'     => (float) $type->default_quota_days,
                    'carryover_days' => 0,
                    'used_days'      => 0,
                    'pending_days'   => 0,
                ]);
            }
        }

        // 2) สร้าง LeaveRequest ตัวอย่าง
        $samples = [
            // EMP001 — ลาพักร้อน 2 วัน อนุมัติแล้ว (ผ่านมา)
            [
                'emp_idx' => 0, 'type' => 'ANNUAL',
                'start' => Carbon::now()->subDays(15), 'end' => Carbon::now()->subDays(14),
                'half'  => false,
                'reason' => 'ไปต่างจังหวัดกับครอบครัว',
                'status' => LeaveRequest::STATUS_APPROVED,
            ],
            // EMP002 — ลาป่วยครึ่งวัน
            [
                'emp_idx' => 1, 'type' => 'SICK',
                'start' => Carbon::now()->subDays(7), 'end' => Carbon::now()->subDays(7),
                'half'  => true, 'period' => 'morning',
                'reason' => 'ปวดหัว ไปพบแพทย์',
                'status' => LeaveRequest::STATUS_APPROVED,
            ],
            // EMP004 — ลากิจ 1 วัน อนุมัติแล้ว
            [
                'emp_idx' => 3, 'type' => 'PERSONAL',
                'start' => Carbon::now()->subDays(10), 'end' => Carbon::now()->subDays(10),
                'half'  => false,
                'reason' => 'ทำธุระที่ขนส่ง',
                'status' => LeaveRequest::STATUS_APPROVED,
            ],
            // EMP005 — ลาป่วย 3 วัน — รอการอนุมัติ
            [
                'emp_idx' => 4, 'type' => 'SICK',
                'start' => Carbon::now()->addDays(1), 'end' => Carbon::now()->addDays(3),
                'half'  => false,
                'reason' => 'ไข้หวัดใหญ่ต้องพักรักษาตัว',
                'status' => LeaveRequest::STATUS_PENDING,
            ],
            // EMP006 — ลาพักร้อน 5 วัน — รอการอนุมัติ
            [
                'emp_idx' => 5, 'type' => 'ANNUAL',
                'start' => Carbon::now()->addDays(15), 'end' => Carbon::now()->addDays(19),
                'half'  => false,
                'reason' => 'ท่องเที่ยวต่างประเทศ',
                'status' => LeaveRequest::STATUS_PENDING,
            ],
            // EMP007 — ลากิจ — ถูกปฏิเสธ
            [
                'emp_idx' => 6, 'type' => 'PERSONAL',
                'start' => Carbon::now()->subDays(3), 'end' => Carbon::now()->subDays(3),
                'half'  => false,
                'reason' => 'ไปงานเลี้ยง',
                'status' => LeaveRequest::STATUS_REJECTED,
                'review_note' => 'ช่วงนี้งานเร่ง ไม่อนุมัติ',
            ],
            // EMP008 — ลาป่วย 1 วัน
            [
                'emp_idx' => 7, 'type' => 'SICK',
                'start' => Carbon::now()->subDays(20), 'end' => Carbon::now()->subDays(20),
                'half'  => false,
                'reason' => 'ไม่สบาย',
                'status' => LeaveRequest::STATUS_APPROVED,
            ],
            // EMP010 — ลาพักร้อนครึ่งวัน — รอการอนุมัติ
            [
                'emp_idx' => 9, 'type' => 'ANNUAL',
                'start' => Carbon::now()->addDays(7), 'end' => Carbon::now()->addDays(7),
                'half'  => true, 'period' => 'afternoon',
                'reason' => 'ติดธุระช่วงบ่าย',
                'status' => LeaveRequest::STATUS_PENDING,
            ],
            // EMP003 — ลากิจ — ยกเลิก
            [
                'emp_idx' => 2, 'type' => 'PERSONAL',
                'start' => Carbon::now()->addDays(20), 'end' => Carbon::now()->addDays(20),
                'half'  => false,
                'reason' => 'นัดหมอฟัน',
                'status' => LeaveRequest::STATUS_CANCELLED,
            ],
            // EMP009 — ลาป่วย — รอการอนุมัติ (วันนี้)
            [
                'emp_idx' => 8, 'type' => 'SICK',
                'start' => Carbon::now(), 'end' => Carbon::now(),
                'half'  => false,
                'reason' => 'มีไข้ต่ำ ๆ ต้องพัก',
                'status' => LeaveRequest::STATUS_PENDING,
            ],
        ];

        $seq = 1;
        foreach ($samples as $s) {
            if (! isset($employees[$s['emp_idx']])) continue;
            if (! isset($types[$s['type']])) continue;

            $emp  = $employees[$s['emp_idx']];
            $type = $types[$s['type']];

            $start = Carbon::parse($s['start'])->startOfDay();
            $end   = Carbon::parse($s['end'])->startOfDay();
            $half  = $s['half'] ?? false;
            $days  = $half ? 0.5 : ($start->diffInDays($end) + 1);

            $now    = Carbon::now();
            $requestNo = sprintf('LV%s%04d', $now->format('ym'), $seq++);

            $req = LeaveRequest::create([
                'request_no'      => $requestNo,
                'employee_id'     => $emp->id,
                'leave_type_id'   => $type->id,
                'start_date'      => $start->toDateString(),
                'end_date'        => $end->toDateString(),
                'is_half_day'     => $half,
                'half_day_period' => $half ? ($s['period'] ?? 'morning') : null,
                'total_days'      => $days,
                'reason'          => $s['reason'] ?? null,
                'contact_phone'   => $emp->phone,
                'status'          => $s['status'],
                'reviewed_by'     => in_array($s['status'], [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED]) ? $hrUser?->id : null,
                'reviewed_at'     => in_array($s['status'], [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED]) ? $now : null,
                'review_note'     => $s['review_note'] ?? null,
            ]);

            // logs
            LeaveRequestLog::create([
                'leave_request_id' => $req->id,
                'action'           => 'submitted',
                'from_status'      => null,
                'to_status'        => LeaveRequest::STATUS_PENDING,
                'note'             => null,
                'user_id'          => $emp->user_id,
            ]);

            if ($s['status'] === LeaveRequest::STATUS_APPROVED) {
                LeaveRequestLog::create([
                    'leave_request_id' => $req->id,
                    'action'           => 'approved',
                    'from_status'      => LeaveRequest::STATUS_PENDING,
                    'to_status'        => LeaveRequest::STATUS_APPROVED,
                    'note'             => 'อนุมัติ',
                    'user_id'          => $hrUser?->id,
                ]);
                // อัปเดต used_days
                LeaveBalance::where('employee_id', $emp->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->increment('used_days', $days);
            } elseif ($s['status'] === LeaveRequest::STATUS_REJECTED) {
                LeaveRequestLog::create([
                    'leave_request_id' => $req->id,
                    'action'           => 'rejected',
                    'from_status'      => LeaveRequest::STATUS_PENDING,
                    'to_status'        => LeaveRequest::STATUS_REJECTED,
                    'note'             => $s['review_note'] ?? null,
                    'user_id'          => $hrUser?->id,
                ]);
            } elseif ($s['status'] === LeaveRequest::STATUS_PENDING) {
                LeaveBalance::where('employee_id', $emp->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->increment('pending_days', $days);
            } elseif ($s['status'] === LeaveRequest::STATUS_CANCELLED) {
                LeaveRequestLog::create([
                    'leave_request_id' => $req->id,
                    'action'           => 'cancelled',
                    'from_status'      => LeaveRequest::STATUS_PENDING,
                    'to_status'        => LeaveRequest::STATUS_CANCELLED,
                    'note'             => null,
                    'user_id'          => $emp->user_id,
                ]);
            }
        }
    }
}

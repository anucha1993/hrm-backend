<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceImportController;
use App\Http\Controllers\Api\Attendance\AttendanceSummaryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Leave\LeaveRequestController;
use App\Http\Controllers\Api\Leave\LeaveTypeController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmploymentTypeController;
use App\Http\Controllers\Api\GoodsDepositController;
use App\Http\Controllers\Api\HipTimeIngestController;
use App\Http\Controllers\Api\LabourController;
use App\Http\Controllers\Api\OfficeLocationController;
use App\Http\Controllers\Api\Payroll\CompensationComponentController;
use App\Http\Controllers\Api\Payroll\CompensationProfileController;
use App\Http\Controllers\Api\Payroll\EmployeeAdvanceController;
use App\Http\Controllers\Api\Payroll\EmployeePayrollController;
use App\Http\Controllers\Api\Payroll\OtSessionController;
use App\Http\Controllers\Api\Payroll\PayrollPeriodController;
use App\Http\Controllers\Api\Payroll\PayrollSlipController;
use App\Http\Controllers\Api\Payroll\WorkOrderController;
use App\Http\Controllers\Api\Payroll\ProductionRateController;
use App\Http\Controllers\Api\Payroll\TaxSettingController;
use App\Http\Controllers\Api\PayrollRuleController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkProfileController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\WorkShiftController;
use App\Http\Controllers\Api\ShiftRotationController;
use App\Http\Controllers\Api\ShiftDayOverrideController;
use App\Http\Controllers\Api\ShiftSwapRequestController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);

// เชื่อมต่อจาก sync agent ของ HIP Time 4.0 (token auth, ไม่ใช่ user login)
Route::middleware('hiptime.token')->post('/hiptime/ingest', [HipTimeIngestController::class, 'store']);

// Authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/me/password', [AuthController::class, 'changePassword']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Users management
    Route::middleware('permission:users.view')->get('/users', [UserController::class, 'index']);
    Route::middleware('permission:users.view')->get('/users/{user}', [UserController::class, 'show']);
    Route::middleware('permission:users.create')->post('/users', [UserController::class, 'store']);
    Route::middleware('permission:users.update')->put('/users/{user}', [UserController::class, 'update']);
    Route::middleware('permission:users.delete')->delete('/users/{user}', [UserController::class, 'destroy']);

    // Roles & Permissions
    Route::middleware('permission:roles.view')->get('/roles', [RoleController::class, 'index']);
    Route::middleware('permission:roles.view')->get('/permissions', [RoleController::class, 'permissions']);
    Route::middleware('permission:roles.view')->get('/roles/{role}', [RoleController::class, 'show']);
    Route::middleware('permission:roles.create')->post('/roles', [RoleController::class, 'store']);
    Route::middleware('permission:roles.update')->put('/roles/{role}', [RoleController::class, 'update']);
    Route::middleware('permission:roles.delete')->delete('/roles/{role}', [RoleController::class, 'destroy']);

    // Master data: ใช้ employees.view ในการอ่านพอ (จำเป็นสำหรับฟอร์มพนักงาน)
    Route::middleware('permission:employees.view')->get('/departments', [DepartmentController::class, 'index']);
    Route::middleware('permission:employees.view')->get('/countries', [CountryController::class, 'index']);
    Route::middleware('permission:employees.view')->get('/employment-types', [EmploymentTypeController::class, 'index']);

    // Master data CRUD: ผูกกับ master_data.manage
    Route::middleware('permission:master_data.manage')->group(function () {
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

        Route::post('/countries', [CountryController::class, 'store']);
        Route::put('/countries/{country}', [CountryController::class, 'update']);
        Route::delete('/countries/{country}', [CountryController::class, 'destroy']);

        Route::post('/employment-types', [EmploymentTypeController::class, 'store']);
        Route::put('/employment-types/{employmentType}', [EmploymentTypeController::class, 'update']);
        Route::delete('/employment-types/{employmentType}', [EmploymentTypeController::class, 'destroy']);
    });

    // Employees
    Route::middleware('permission:employees.view')->get('/employees', [EmployeeController::class, 'index']);
    // Import (ต้องอยู่ก่อน /employees/{employee} เพื่อกัน route conflict)
    Route::middleware('permission:employees.create')->get('/employees/import/template', [\App\Http\Controllers\Api\EmployeeImportController::class, 'template']);
    Route::middleware('permission:employees.create')->post('/employees/import', [\App\Http\Controllers\Api\EmployeeImportController::class, 'import']);
    Route::middleware('permission:employees.view')->get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::middleware('permission:employees.create')->post('/employees', [EmployeeController::class, 'store']);
    Route::middleware('permission:employees.update')->post('/employees/{employee}', [EmployeeController::class, 'update']); // POST + _method=PUT for multipart
    Route::middleware('permission:employees.update')->put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::middleware('permission:employees.delete')->delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

    // Labour API (proxy ไประบบ charoenmunconcrete.net)
    Route::middleware('permission:labours.view')->group(function () {
        Route::get('/labours', [LabourController::class, 'index']);
        Route::get('/labours/passport/{passport}', [LabourController::class, 'showByPassport']);
        Route::get('/labours/{id}', [LabourController::class, 'show'])->whereNumber('id');
    });

    // Attendance: ลงเวลาเข้า-ออก (พนักงานทุกคนที่มี attendance.checkin)
    Route::middleware('permission:attendance.checkin')->group(function () {
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::get('/attendance/today', [AttendanceController::class, 'todayStatus']);
        Route::get('/attendance/my-history', [AttendanceController::class, 'myHistory']);
    });

    // Attendance: ดูรวม / จัดการ
    Route::middleware('permission:attendance.view')->get('/attendance', [AttendanceController::class, 'index']);
    Route::middleware('permission:attendance.view')->get('/attendance/roster', [AttendanceController::class, 'roster']);
    Route::middleware('permission:attendance.view')->get('/attendance/export', [AttendanceController::class, 'export']);

    // Attendance: นำเข้าเวลาทำงานจาก Excel (HR/Admin)
    // ต้องอยู่ก่อน route ที่มี /attendance/{attendance} เพื่อกัน route conflict
    Route::middleware('permission:attendance.manage')->group(function () {
        Route::get('/attendance/import/template', [AttendanceImportController::class, 'template']);
        Route::post('/attendance/import', [AttendanceImportController::class, 'import']);
    });

    // Attendance master data (สถานที่ + กะ)
    Route::middleware('permission:attendance.view')->group(function () {
        Route::get('/office-locations', [OfficeLocationController::class, 'index']);
        Route::get('/work-shifts', [WorkShiftController::class, 'index']);
        Route::get('/work-profiles', [WorkProfileController::class, 'index']);
        Route::get('/work-profiles/{workProfile}', [WorkProfileController::class, 'show']);
        Route::get('/holidays', [HolidayController::class, 'index']);

        // หมุนเวียนกะ + กะรายวัน (อ่าน)
        Route::get('/shift-rotations', [ShiftRotationController::class, 'index']);
        Route::get('/shift-rotations/{shiftRotation}', [ShiftRotationController::class, 'show']);
        Route::get('/shift-overrides', [ShiftDayOverrideController::class, 'index']);
    });

    Route::middleware('permission:attendance.manage')->group(function () {
        Route::post('/office-locations', [OfficeLocationController::class, 'store']);
        Route::put('/office-locations/{officeLocation}', [OfficeLocationController::class, 'update']);
        Route::delete('/office-locations/{officeLocation}', [OfficeLocationController::class, 'destroy']);

        Route::post('/work-shifts', [WorkShiftController::class, 'store']);
        Route::put('/work-shifts/{workShift}', [WorkShiftController::class, 'update']);
        Route::delete('/work-shifts/{workShift}', [WorkShiftController::class, 'destroy']);

        Route::post('/work-profiles', [WorkProfileController::class, 'store']);
        Route::put('/work-profiles/{workProfile}', [WorkProfileController::class, 'update']);
        Route::delete('/work-profiles/{workProfile}', [WorkProfileController::class, 'destroy']);
        Route::put('/work-profiles/{workProfile}/departments', [WorkProfileController::class, 'syncDepartments']);

        Route::post('/holidays', [HolidayController::class, 'store']);
        Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

        // หมุนเวียนกะ (จัดการ)
        Route::post('/shift-rotations', [ShiftRotationController::class, 'store']);
        Route::put('/shift-rotations/{shiftRotation}', [ShiftRotationController::class, 'update']);
        Route::delete('/shift-rotations/{shiftRotation}', [ShiftRotationController::class, 'destroy']);
        Route::post('/shift-rotations/{shiftRotation}/assignments', [ShiftRotationController::class, 'storeAssignment']);
        Route::delete('/shift-rotations/{shiftRotation}/assignments/{assignment}', [ShiftRotationController::class, 'destroyAssignment']);

        // กะรายวัน (ปรับมือ)
        Route::post('/shift-overrides', [ShiftDayOverrideController::class, 'store']);
        Route::delete('/shift-overrides/{shiftDayOverride}', [ShiftDayOverrideController::class, 'destroy']);

        // คำขอสลับกะ (เจ้าหน้าที่/แอดมินเท่านั้น)
        Route::get('/shift-swaps', [ShiftSwapRequestController::class, 'index']);
        Route::post('/shift-swaps', [ShiftSwapRequestController::class, 'store']);
        Route::post('/shift-swaps/{shiftSwapRequest}/approve', [ShiftSwapRequestController::class, 'approve']);
        Route::post('/shift-swaps/{shiftSwapRequest}/reject', [ShiftSwapRequestController::class, 'reject']);
        Route::post('/shift-swaps/{shiftSwapRequest}/cancel', [ShiftSwapRequestController::class, 'cancel']);
        Route::delete('/shift-swaps/{shiftSwapRequest}', [ShiftSwapRequestController::class, 'destroy']);

        // แก้ไข/เพิ่ม/ลบ Attendance ย้อนหลัง (HR/Admin)
        Route::post('/attendance/manual', [AttendanceController::class, 'storeManual']);
        Route::post('/attendance/manual-bulk', [AttendanceController::class, 'storeManualBulk']);
        Route::patch('/attendance/{attendance}', [AttendanceController::class, 'update']);
        Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroy']);
        Route::get('/attendance/{attendance}/audit-logs', [AttendanceController::class, 'auditLogs']);
    });

    /* ========================= PAYROLL ========================= */
    // ตั้งค่าระบบเงินเดือน (HR/Admin)
    Route::middleware('permission:payroll.config')->group(function () {
        Route::get('/payroll/profiles', [CompensationProfileController::class, 'index']);
        Route::get('/payroll/profiles/{profile}', [CompensationProfileController::class, 'show']);
        Route::post('/payroll/profiles', [CompensationProfileController::class, 'store']);
        Route::put('/payroll/profiles/{profile}', [CompensationProfileController::class, 'update']);
        Route::delete('/payroll/profiles/{profile}', [CompensationProfileController::class, 'destroy']);

        Route::get('/payroll/components', [CompensationComponentController::class, 'index']);
        Route::post('/payroll/components', [CompensationComponentController::class, 'store']);
        Route::get('/payroll/components/{component}', [CompensationComponentController::class, 'show']);
        Route::put('/payroll/components/{component}', [CompensationComponentController::class, 'update']);
        Route::delete('/payroll/components/{component}', [CompensationComponentController::class, 'destroy']);

        Route::get('/payroll/tax/brackets', [TaxSettingController::class, 'brackets']);
        Route::put('/payroll/tax/brackets', [TaxSettingController::class, 'syncBrackets']);
        Route::get('/payroll/tax/profiles', [TaxSettingController::class, 'profiles']);
        Route::get('/payroll/tax/profiles/{taxProfile}', [TaxSettingController::class, 'showProfile']);
        Route::post('/payroll/tax/profiles', [TaxSettingController::class, 'storeProfile']);
        Route::put('/payroll/tax/profiles/{taxProfile}', [TaxSettingController::class, 'updateProfile']);
        Route::delete('/payroll/tax/profiles/{taxProfile}', [TaxSettingController::class, 'destroyProfile']);

        // employee-level setup
        Route::get('/payroll/employees/{employee}', [EmployeePayrollController::class, 'show']);
        Route::post('/payroll/employees/{employee}/compensations', [EmployeePayrollController::class, 'storeCompensation']);
        Route::put('/payroll/employees/{employee}/compensations/{compensation}', [EmployeePayrollController::class, 'updateCompensation']);
        Route::delete('/payroll/employees/{employee}/compensations/{compensation}', [EmployeePayrollController::class, 'destroyCompensation']);
        Route::post('/payroll/employees/{employee}/components', [EmployeePayrollController::class, 'storeComponent']);
        Route::put('/payroll/employees/{employee}/components/{component}', [EmployeePayrollController::class, 'updateComponent']);
        Route::delete('/payroll/employees/{employee}/components/{component}', [EmployeePayrollController::class, 'destroyComponent']);
        Route::put('/payroll/employees/{employee}/tax-setting', [EmployeePayrollController::class, 'upsertTaxSetting']);
    });

    // Production rate items (เรทค่าจ้างรายสินค้า / piecework)
    Route::middleware('permission:payroll.view')->get('/payroll/production-rates', [ProductionRateController::class, 'index']);
    Route::middleware('permission:payroll.view')->get('/payroll/production-rates/{item}', [ProductionRateController::class, 'show']);
    Route::middleware('permission:payroll.config')->group(function () {
        Route::post('/payroll/production-rates', [ProductionRateController::class, 'store']);
        Route::put('/payroll/production-rates/{item}', [ProductionRateController::class, 'update']);
        Route::delete('/payroll/production-rates/{item}', [ProductionRateController::class, 'destroy']);
    });

    // Work orders (ใบจ่ายงาน)
    Route::middleware('permission:payroll.view')->group(function () {
        Route::get('/payroll/work-orders', [WorkOrderController::class, 'index']);
        Route::get('/payroll/work-orders/summary', [WorkOrderController::class, 'summary']);
        Route::get('/payroll/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
    });
    Route::middleware('permission:payroll.config')->group(function () {
        Route::post('/payroll/work-orders', [WorkOrderController::class, 'store']);
        Route::put('/payroll/work-orders/{workOrder}', [WorkOrderController::class, 'update']);
        Route::delete('/payroll/work-orders/{workOrder}', [WorkOrderController::class, 'destroy']);
        Route::post('/payroll/work-orders/{workOrder}/daily-entries', [WorkOrderController::class, 'storeDailyEntry']);
        Route::put('/payroll/work-orders/{workOrder}/daily-entries/{dailyEntry}', [WorkOrderController::class, 'updateDailyEntry']);
        Route::delete('/payroll/work-orders/{workOrder}/daily-entries/{dailyEntry}', [WorkOrderController::class, 'destroyDailyEntry']);
        Route::post('/payroll/work-orders/{workOrder}/link-batch', [WorkOrderController::class, 'linkBatch']);
        Route::post('/payroll/work-orders/{workOrder}/unlink-batch', [WorkOrderController::class, 'unlinkBatch']);
        Route::post('/payroll/work-orders/import-to-payroll', [WorkOrderController::class, 'importToPayroll']);
    });

    // OT management (HR)
    Route::middleware('permission:payroll.ot_manage')->group(function () {
        Route::get('/payroll/ot-sessions', [OtSessionController::class, 'index']);
        Route::get('/payroll/ot-sessions/export', [OtSessionController::class, 'export']);
        Route::post('/payroll/ot-sessions', [OtSessionController::class, 'store']);
        Route::get('/payroll/ot-sessions/{otSession}', [OtSessionController::class, 'show']);
        Route::put('/payroll/ot-sessions/{otSession}', [OtSessionController::class, 'update']);
        Route::delete('/payroll/ot-sessions/{otSession}', [OtSessionController::class, 'destroy']);
    });

    // Period CRUD (HR)
    Route::middleware('permission:payroll.compute')->group(function () {
        Route::get('/payroll/periods', [PayrollPeriodController::class, 'index']);
        Route::post('/payroll/periods', [PayrollPeriodController::class, 'store']);
        Route::get('/payroll/periods/{period}', [PayrollPeriodController::class, 'show']);
        Route::put('/payroll/periods/{period}', [PayrollPeriodController::class, 'update']);
        Route::delete('/payroll/periods/{period}', [PayrollPeriodController::class, 'destroy']);
        Route::post('/payroll/periods/{period}/compute', [PayrollPeriodController::class, 'compute']);
    });

    // Slip — ดู (any with payroll.view)
    Route::middleware('permission:payroll.view')->group(function () {
        Route::get('/payroll/slips', [PayrollSlipController::class, 'index']);
        Route::get('/payroll/slips/export', [PayrollSlipController::class, 'export']);
        Route::get('/payroll/slips/{slip}', [PayrollSlipController::class, 'show']);
    });

    // Slip — submit/cancel/delete (HR)
    Route::middleware('permission:payroll.compute')->group(function () {
        Route::delete('/payroll/slips/{slip}', [PayrollSlipController::class, 'destroy']);
        Route::post('/payroll/slips/{slip}/submit-l1', [PayrollSlipController::class, 'submitL1']);
        Route::post('/payroll/slips/{slip}/cancel', [PayrollSlipController::class, 'cancel']);
    });

    // Approve L1 (Manager)
    Route::middleware('permission:payroll.approve_l1')->group(function () {
        Route::post('/payroll/slips/{slip}/approve-l1', [PayrollSlipController::class, 'approveL1']);
        Route::post('/payroll/slips/{slip}/reject-l1', [PayrollSlipController::class, 'rejectL1']);
    });

    // Approve L2 + จ่ายเงิน (Owner)
    Route::middleware('permission:payroll.approve_l2')->group(function () {
        Route::post('/payroll/slips/{slip}/approve-l2', [PayrollSlipController::class, 'approveL2']);
        Route::post('/payroll/slips/{slip}/reject-l2', [PayrollSlipController::class, 'rejectL2']);
    });
    Route::middleware('permission:payroll.pay')->group(function () {
        Route::post('/payroll/slips/{slip}/mark-paid', [PayrollSlipController::class, 'markPaid']);
    });

    // bulk action — ตรวจสิทธิ์ใน controller ตาม action
    Route::middleware('permission:payroll.view')->post('/payroll/slips/bulk', [PayrollSlipController::class, 'bulkAction']);

    /* ========================= LEAVE ========================= */
    // Leave types — config (HR)
    Route::get('/leave/types', [LeaveTypeController::class, 'index']); // ทุกคนเห็น list สำหรับยื่นขอ
    Route::middleware('permission:leave.config')->group(function () {
        Route::post('/leave/types', [LeaveTypeController::class, 'store']);
        Route::get('/leave/types/{leaveType}', [LeaveTypeController::class, 'show']);
        Route::put('/leave/types/{leaveType}', [LeaveTypeController::class, 'update']);
        Route::delete('/leave/types/{leaveType}', [LeaveTypeController::class, 'destroy']);
        Route::put('/leave/balances', [LeaveRequestController::class, 'updateBalance']);
    });

    // Leave requests — ทุกคนยื่นได้ + ดูของตนเอง
    Route::middleware('permission:leave.request')->group(function () {
        Route::get('/leave/requests', [LeaveRequestController::class, 'index']);
        Route::get('/leave/requests/export', [LeaveRequestController::class, 'export']);
        Route::get('/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
        Route::post('/leave/requests', [LeaveRequestController::class, 'store']);
        Route::post('/leave/requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
        Route::get('/leave/balances', [LeaveRequestController::class, 'balances']);
    });

    Route::middleware('permission:leave.approve')->group(function () {
        Route::post('/leave/requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('/leave/requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
    });

    /* ========================= เบิกเงินล่วงหน้า (ADVANCE) ========================= */
    Route::middleware('permission:advance.request')->group(function () {
        Route::get('/advances', [EmployeeAdvanceController::class, 'index']);
        Route::get('/advances/{advance}', [EmployeeAdvanceController::class, 'show']);
        Route::post('/advances', [EmployeeAdvanceController::class, 'store']);
        Route::post('/advances/{advance}/cancel', [EmployeeAdvanceController::class, 'cancel']);
    });

    Route::middleware('permission:advance.approve')->group(function () {
        Route::post('/advances/{advance}/approve', [EmployeeAdvanceController::class, 'approve']);
        Route::post('/advances/{advance}/reject', [EmployeeAdvanceController::class, 'reject']);
        Route::post('/advances/{advance}/mark-paid', [EmployeeAdvanceController::class, 'markPaid']);
        Route::post('/advances/{advance}/repayments', [EmployeeAdvanceController::class, 'addRepayment']);
        Route::delete('/advance-repayments/{repayment}', [EmployeeAdvanceController::class, 'deleteRepayment']);
    });

    /* ========================= ATTENDANCE SUMMARY ========================= */
    Route::middleware('permission:attendance.checkin')->group(function () {
        // ทุกคน: ดูสรุปของตัวเอง (controller จะ enforce)
        Route::get('/attendance/summary', [AttendanceSummaryController::class, 'index']);
        Route::get('/attendance/summary/export', [AttendanceSummaryController::class, 'export']);
        Route::get('/attendance/summary/{employee}/daily', [AttendanceSummaryController::class, 'daily']);
        Route::get('/attendance/summary/{employee}/daily/export', [AttendanceSummaryController::class, 'dailyExport']);
    });

    /* ========================= TASKS (มอบหมายงาน) ========================= */
    // อ่าน + ของตนเอง (controller filter ตามสิทธิ์)
    Route::middleware('permission:tasks.view')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/summary', [TaskController::class, 'summary']);
        Route::get('/tasks/{task}', [TaskController::class, 'show']);
        // ผู้รับงานอัปโหลดรูป + ส่งงาน
        Route::post('/tasks/{task}/assignees/{assignee}/photo', [TaskController::class, 'uploadPhoto']);
        Route::post('/tasks/{task}/assignees/{assignee}/submit', [TaskController::class, 'submit']);
    });

    // จัดการ (admin)
    Route::middleware('permission:tasks.manage')->group(function () {
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::put('/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        Route::post('/tasks/{task}/assignees/{assignee}/rate', [TaskController::class, 'rate']);
        Route::post('/tasks/{task}/assignees/{assignee}/reject', [TaskController::class, 'reject']);
    });

    /* ========================= RULES (กฎระเบียบ / หัก-เพิ่มเงิน) ========================= */
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/payroll-rules', [PayrollRuleController::class, 'index']);
        Route::get('/payroll-rules/meta', [PayrollRuleController::class, 'meta']);
        Route::get('/payroll-rules/{rule}', [PayrollRuleController::class, 'show']);
        Route::get('/payroll-settings', [PayrollRuleController::class, 'settingsIndex']);
    });
    Route::middleware('permission:settings.manage')->group(function () {
        Route::post('/payroll-rules', [PayrollRuleController::class, 'store']);
        Route::put('/payroll-rules/{rule}', [PayrollRuleController::class, 'update']);
        Route::delete('/payroll-rules/{rule}', [PayrollRuleController::class, 'destroy']);
        Route::post('/payroll-rules/{rule}/toggle', [PayrollRuleController::class, 'toggle']);
        Route::put('/payroll-settings', [PayrollRuleController::class, 'settingsBulkUpdate']);
    });

    // ประวัติการ sync จาก HIP Time agent
    Route::middleware('permission:settings.view')->get('/hiptime/sync-logs', [HipTimeIngestController::class, 'syncLogs']);

    /* ========================= GOODS DEPOSITS (ใบมัดจำของใช้ทั่วไป) ========================= */
    Route::middleware('permission:goods_deposits.view')->group(function () {
        Route::get('/goods-deposits', [GoodsDepositController::class, 'index']);
        Route::get('/goods-deposits/preview-for-payslip/{payslip}', [GoodsDepositController::class, 'previewForPayslip']);
        Route::get('/goods-deposits/{deposit}', [GoodsDepositController::class, 'show']);
    });
    Route::middleware('permission:goods_deposits.create')->group(function () {
        Route::post('/goods-deposits', [GoodsDepositController::class, 'store']);
    });
    Route::middleware('permission:goods_deposits.update')->group(function () {
        Route::put('/goods-deposits/{deposit}', [GoodsDepositController::class, 'update']);
        Route::post('/goods-deposits/{deposit}/status', [GoodsDepositController::class, 'changeStatus']);
        Route::post('/goods-deposits/apply-to-payslip/{payslip}', [GoodsDepositController::class, 'applyToPayslip']);
        Route::post('/goods-deposits/revoke-from-payslip/{payslip}', [GoodsDepositController::class, 'revokeFromPayslip']);
    });
    Route::middleware('permission:goods_deposits.delete')->group(function () {
        Route::delete('/goods-deposits/{deposit}', [GoodsDepositController::class, 'destroy']);
    });

    /* ========================= REPORTS ========================= */
    Route::middleware('permission:reports.view')->prefix('reports')->group(function () {
        Route::get('/payroll/periods', [ReportController::class, 'payrollPeriods']);
        Route::get('/payroll/summary', [ReportController::class, 'payrollSummary']);
        Route::get('/payroll/export', [ReportController::class, 'payrollExport']);
        Route::get('/attendance/summary', [ReportController::class, 'attendanceSummary']);
        Route::get('/attendance/export', [ReportController::class, 'attendanceExport']);
        Route::get('/leave/summary', [ReportController::class, 'leaveSummary']);
        Route::get('/leave/export', [ReportController::class, 'leaveExport']);
        Route::get('/employees/summary', [ReportController::class, 'employeesSummary']);
        Route::get('/employees/export', [ReportController::class, 'employeesExport']);
        Route::get('/ot/summary', [ReportController::class, 'otSummary']);
        Route::get('/ot/export', [ReportController::class, 'otExport']);
        Route::get('/tasks/summary', [ReportController::class, 'tasksSummary']);
        Route::get('/tasks/export', [ReportController::class, 'tasksExport']);
        Route::get('/payslip/{slip}', [ReportController::class, 'payslipShow']);
        Route::get('/payslips', [ReportController::class, 'payslipsByPeriod']);
    });
});
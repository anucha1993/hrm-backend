<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmploymentTypeController;
use App\Http\Controllers\Api\LabourController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);

// Authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

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
});
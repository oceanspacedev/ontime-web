<?php

use App\Http\Controllers\Api\EmployeeWebhookController;
use App\Http\Controllers\Api\V1\ApprovalApiController;
use App\Http\Controllers\Api\V1\AttendanceApiController;
use App\Http\Controllers\Api\V1\AttendanceCorrectionApiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LeaveRequestApiController;
use App\Http\Controllers\Api\V1\MasterDataApiController;
use App\Http\Controllers\Api\V1\OvertimeRequestApiController;
use App\Http\Controllers\Api\V1\ReportAndCalendarApiController;
use App\Http\Controllers\Api\V1\ResignationApiController;
use App\Http\Controllers\Api\V1\ShieldApiController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/employees', [EmployeeWebhookController::class, 'handle']);

Route::prefix('v1')->group(function () {

    // Auth Routes
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:api-otp-request');
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:api-otp-verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Shield Permissions & Roles Inspection
        Route::get('/shield/roles', [ShieldApiController::class, 'indexRoles'])->middleware('can:ViewAny:Role');
        Route::get('/shield/permissions', [ShieldApiController::class, 'indexPermissions'])->middleware('can:ViewAny:Role');

        // Attendance Presensi
        Route::get('/attendance/today', [AttendanceApiController::class, 'today']);
        Route::post('/attendance/check-in', [AttendanceApiController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceApiController::class, 'checkOut']);
        Route::post('/attendance/master-face', [AttendanceApiController::class, 'registerMasterFace']);
        Route::delete('/attendance/master-face', [AttendanceApiController::class, 'deleteMasterFace']);
        Route::get('/attendances', [AttendanceApiController::class, 'index']);
        Route::get('/attendances/{id}', [AttendanceApiController::class, 'show']);

        // Leave Requests
        Route::get('/leave-requests', [LeaveRequestApiController::class, 'index']);
        Route::post('/leave-requests', [LeaveRequestApiController::class, 'store']);
        Route::get('/leave-requests/{id}', [LeaveRequestApiController::class, 'show']);
        Route::put('/leave-requests/{id}', [LeaveRequestApiController::class, 'update']);
        Route::delete('/leave-requests/{id}', [LeaveRequestApiController::class, 'destroy']);

        // Overtime Requests
        Route::get('/overtime-requests', [OvertimeRequestApiController::class, 'index']);
        Route::post('/overtime-requests', [OvertimeRequestApiController::class, 'store']);
        Route::get('/overtime-requests/{id}', [OvertimeRequestApiController::class, 'show']);
        Route::put('/overtime-requests/{id}', [OvertimeRequestApiController::class, 'update']);
        Route::delete('/overtime-requests/{id}', [OvertimeRequestApiController::class, 'destroy']);

        // Attendance Corrections
        Route::get('/attendance-corrections', [AttendanceCorrectionApiController::class, 'index']);
        Route::post('/attendance-corrections', [AttendanceCorrectionApiController::class, 'store']);
        Route::get('/attendance-corrections/{id}', [AttendanceCorrectionApiController::class, 'show']);
        Route::put('/attendance-corrections/{id}', [AttendanceCorrectionApiController::class, 'update']);
        Route::delete('/attendance-corrections/{id}', [AttendanceCorrectionApiController::class, 'destroy']);

        // Resignation Requests
        Route::get('/resignations', [ResignationApiController::class, 'index']);
        Route::post('/resignations', [ResignationApiController::class, 'store']);
        Route::get('/resignations/{id}', [ResignationApiController::class, 'show']);
        Route::put('/resignations/{id}', [ResignationApiController::class, 'update']);
        Route::delete('/resignations/{id}', [ResignationApiController::class, 'destroy']);

        // Approval Actions
        Route::get('/approvals/pending', [ApprovalApiController::class, 'pending']);
        Route::put('/approvals/{type}/{id}/process', [ApprovalApiController::class, 'process']);

        // Master Data APIs
        Route::get('/master/companies', [MasterDataApiController::class, 'companies'])->middleware('can:ViewAny:Company');
        Route::get('/master/companies/{id}', [MasterDataApiController::class, 'showCompany'])->middleware('can:ViewAny:Company');

        Route::get('/master/company-locations', [MasterDataApiController::class, 'companyLocations'])->middleware('can:ViewAny:CompanyLocation');
        Route::get('/master/company-locations/{id}', [MasterDataApiController::class, 'showCompanyLocation'])->middleware('can:ViewAny:CompanyLocation');

        Route::get('/master/company-policies', [MasterDataApiController::class, 'companyPolicies'])->middleware('can:ViewAny:CompanyPolicy');
        Route::get('/master/company-policies/{id}', [MasterDataApiController::class, 'showCompanyPolicy'])->middleware('can:ViewAny:CompanyPolicy');

        Route::get('/master/divisions', [MasterDataApiController::class, 'divisions'])->middleware('can:ViewAny:Division');
        Route::get('/master/divisions/{id}', [MasterDataApiController::class, 'showDivision'])->middleware('can:ViewAny:Division');

        Route::get('/master/job-titles', [MasterDataApiController::class, 'jobTitles'])->middleware('can:ViewAny:JobTitle');
        Route::get('/master/job-titles/{id}', [MasterDataApiController::class, 'showJobTitle'])->middleware('can:ViewAny:JobTitle');

        Route::get('/master/job-levels', [MasterDataApiController::class, 'jobLevels'])->middleware('can:ViewAny:JobLevel');
        Route::get('/master/job-levels/{id}', [MasterDataApiController::class, 'showJobLevel'])->middleware('can:ViewAny:JobLevel');

        Route::get('/master/employees', [MasterDataApiController::class, 'employees'])->middleware('can:ViewAny:Employee');
        Route::get('/master/employees/{id}', [MasterDataApiController::class, 'showEmployee'])->middleware('can:ViewAny:Employee');

        Route::get('/master/interns', [MasterDataApiController::class, 'interns'])->middleware('can:ViewAny:Intern');
        Route::get('/master/interns/{id}', [MasterDataApiController::class, 'showIntern'])->middleware('can:ViewAny:Intern');

        Route::get('/master/freelancers', [MasterDataApiController::class, 'freelancers'])->middleware('can:ViewAny:Freelancer');
        Route::get('/master/freelancers/{id}', [MasterDataApiController::class, 'showFreelancer'])->middleware('can:ViewAny:Freelancer');

        Route::get('/master/holidays', [MasterDataApiController::class, 'holidays'])->middleware('can:ViewAny:Holiday');
        Route::get('/master/holidays/{id}', [MasterDataApiController::class, 'showHoliday'])->middleware('can:ViewAny:Holiday');

        Route::get('/master/approval-flows', [MasterDataApiController::class, 'approvalFlows'])->middleware('can:ViewAny:ApprovalFlow');
        Route::get('/master/approval-flows/{id}', [MasterDataApiController::class, 'showApprovalFlow'])->middleware('can:ViewAny:ApprovalFlow');

        Route::get('/master/approvers', [MasterDataApiController::class, 'approvers'])->middleware('can:ViewAny:Approver');
        Route::get('/master/approvers/{id}', [MasterDataApiController::class, 'showApprover'])->middleware('can:ViewAny:Approver');

        Route::get('/master/users', [MasterDataApiController::class, 'users'])->middleware('can:ViewAny:User');
        Route::get('/master/users/{id}', [MasterDataApiController::class, 'showUser'])->middleware('can:ViewAny:User');

        // Reports & Calendar APIs
        Route::get('/kalender-cuti', [ReportAndCalendarApiController::class, 'kalenderCuti']);
        Route::get('/laporan-absensi', [ReportAndCalendarApiController::class, 'laporanAbsensi']);
    });
});

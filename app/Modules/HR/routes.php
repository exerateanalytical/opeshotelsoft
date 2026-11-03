<?php

use App\Modules\HR\Http\HrController as H;
use App\Modules\HR\Http\HrExtrasController as X;
use App\Modules\HR\Http\PayrollController as P;
use Illuminate\Support\Facades\Route;

Route::prefix('hr')->group(function () {
    Route::get('departments', [H::class, 'departments']);
    Route::put('departments', [H::class, 'saveDepartment']);
    Route::get('positions', [H::class, 'positions']);
    Route::post('positions', [H::class, 'savePosition']);
    Route::get('employees', [H::class, 'employees']);
    Route::post('employees', [H::class, 'hire']);
    Route::get('employees/{employee}', [H::class, 'showEmployee']);
    Route::patch('employees/{employee}', [H::class, 'updateEmployee']);
    Route::post('employees/{employee}/terminate', [H::class, 'terminate']);
    Route::post('employees/{employee}/advances', [H::class, 'grantAdvance']);
    Route::get('employees/{employee}/advances', [H::class, 'advances']);
    Route::get('attendance', [H::class, 'attendance']);
    Route::get('clock/status', [H::class, 'clockStatus']);
    Route::get('shifts', [H::class, 'shifts']);
    Route::put('shifts', [H::class, 'saveShifts']);
    Route::post('clock/{direction}', [H::class, 'clock'])->whereIn('direction', ['in', 'out']);
    Route::get('leave-types', [H::class, 'leaveTypes']);
    Route::get('leave-requests', [H::class, 'leaveRequests']);
    Route::post('leave-requests', [H::class, 'requestLeave']);
    Route::post('leave-requests/{leave}/decide', [H::class, 'decideLeave']);

    Route::get('employees/{employee}/documents', [X::class, 'documents']);
    Route::post('employees/{employee}/documents', [X::class, 'uploadDocument']);
    Route::get('documents/expiring', [X::class, 'expiring']);
    Route::get('documents/{document}/download', [X::class, 'download']);
    Route::get('org-chart', [X::class, 'orgChart']);
    Route::get('onboarding-templates', [X::class, 'templates']);
    Route::put('onboarding-templates/{position}', [X::class, 'saveTemplate']);
    Route::get('employees/{employee}/onboarding', [X::class, 'onboarding']);
    Route::get('onboarding-tasks/mine', [X::class, 'myOnboardingTasks']);
    Route::post('onboarding-tasks/{task}/complete', [X::class, 'completeTask']);
    Route::get('overtime', [X::class, 'overtimeEntries']);
    Route::post('overtime/compute', [X::class, 'computeOvertime']);
    Route::get('overtime/rules', [X::class, 'overtimeRules']);
    Route::post('overtime/{entry}/decide', [X::class, 'decideOvertime']);
    Route::get('public-holidays', [X::class, 'publicHolidays']);
    Route::post('public-holidays', [X::class, 'addPublicHoliday']);
    Route::delete('public-holidays/{holiday}', [X::class, 'removePublicHoliday']);
});

Route::prefix('payroll')->group(function () {
    Route::get('runs', [P::class, 'runs']);
    Route::post('runs', [P::class, 'compute']);
    Route::get('runs/{run}', [P::class, 'show']);
    Route::post('runs/{run}/approve', [P::class, 'approve']);
    Route::post('runs/{run}/pay', [P::class, 'pay']);
    Route::get('runs/{run}/payment-file', [P::class, 'paymentFile']);
    Route::get('runs/{run}/declaration', [P::class, 'declaration']);
    Route::get('payslips/mine', [P::class, 'mine']);
    Route::get('payslips/{payslip}', [P::class, 'payslip']);
    Route::get('rule-packs', [P::class, 'rulePacks']);
    Route::post('rule-packs/{pack}/certify', [P::class, 'certify']);
});

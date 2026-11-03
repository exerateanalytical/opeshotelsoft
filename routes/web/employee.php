<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// H-C employee 360 profile page. Sections load from /api/v1/hr/employees/{id}/profile/*.
// Same rule as the HR API: hr.view, hr.manage or payroll.manage (hr-officer holds hr.manage only).
Route::middleware(['auth'])->group(function () {
    Route::get('/hr/employees/{id}', function (Request $request, int $id) {
        $u = $request->user();
        abort_unless($u->can('hr.view') || $u->can('hr.manage') || $u->can('payroll.manage'), 403);

        return Inertia::render('HR/EmployeeProfile', ['employeeId' => $id]);
    })->whereNumber('id')->name('hr.employee');
});

<?php

use App\Modules\HR\Workforce\Http\WorkforceController as W;
use Illuminate\Support\Facades\Route;

// H-A workforce. Included from app/Modules/HR/routes.php; permissions checked in the controller.
Route::prefix('workforce')->controller(W::class)->group(function () {
    Route::get('settings', 'settings');
    Route::put('settings', 'saveSettings');
    Route::get('dept-rules', 'deptRules');
    Route::put('dept-rules', 'saveDeptRule');
    Route::get('forecast', 'forecast');
    Route::post('check', 'check');

    Route::get('rosters', 'rosters');
    Route::post('rosters/generate', 'generate');
    Route::get('rosters/{roster}', 'showRoster');
    Route::put('rosters/{roster}/shifts', 'saveShifts');
    Route::post('rosters/{roster}/publish', 'publish');

    Route::get('my-shifts', 'myShifts');
    Route::get('colleague-shifts', 'colleagueShifts');
    Route::get('open-shifts', 'openShifts');
    Route::post('open-shifts/{shift}/claim', 'claim');
    Route::get('requests', 'requests');
    Route::post('swaps', 'requestSwap');
    Route::post('requests/{req}/respond', 'respondSwap');
    Route::post('requests/{req}/cancel', 'cancelRequest');
    Route::post('requests/{req}/decide', 'decideRequest');

    Route::get('leave/policies', 'leavePolicies');
    Route::put('leave/policies', 'saveLeavePolicy');
    Route::post('leave/accrue', 'accrue');
    Route::post('leave/year-end', 'yearEnd');
    Route::post('leave/expire', 'expireLeave');
    Route::post('leave/adjust', 'adjustLeave');
    Route::get('leave/balances', 'leaveBalances');

    Route::post('clock/{direction}', 'clockSelf')->whereIn('direction', ['in', 'out']);
    Route::post('kiosk/{direction}', 'kioskClock')->whereIn('direction', ['in', 'out']);
    Route::put('employees/{employee}/pin', 'setPin');
    Route::get('anomalies', 'anomalies');
    Route::post('anomalies/{anomaly}/review', 'reviewAnomaly');
    Route::get('anomalies/{anomaly}/photo', 'photo');
    Route::post('scan', 'scan');

    Route::get('dashboard', 'dashboard');
});

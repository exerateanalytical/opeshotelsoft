<?php

use App\Modules\Maintenance\Http\MaintenanceController as M;
use Illuminate\Support\Facades\Route;

Route::prefix('maintenance')->group(function () {
    Route::get('work-orders', [M::class, 'index']);
    Route::post('work-orders', [M::class, 'store']);
    Route::get('work-orders/{workOrder}', [M::class, 'show']);
    Route::post('work-orders/{workOrder}/assign', [M::class, 'assign']);
    Route::post('work-orders/{workOrder}/start', [M::class, 'start']);
    Route::post('work-orders/{workOrder}/complete', [M::class, 'complete']);
    Route::post('work-orders/{workOrder}/hold', [M::class, 'hold']);
    Route::post('work-orders/{workOrder}/resume', [M::class, 'resume']);
    Route::post('work-orders/{workOrder}/cancel', [M::class, 'cancel']);
    Route::get('schedules', [M::class, 'schedules']);
    Route::post('schedules', [M::class, 'storeSchedule']);
    Route::post('schedules/run', [M::class, 'runPreventive']);
    Route::patch('schedules/{schedule}', [M::class, 'updateSchedule']);
});

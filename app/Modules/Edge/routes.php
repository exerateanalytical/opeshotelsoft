<?php

use App\Modules\Edge\Http\EdgeController;
use Illuminate\Support\Facades\Route;

Route::prefix('edge')->group(function () {
    Route::get('status', [EdgeController::class, 'status']);
    Route::post('nodes', [EdgeController::class, 'register']);
    Route::post('nodes/{node}/revoke', [EdgeController::class, 'revoke']);
    Route::post('sync', [EdgeController::class, 'syncNow']);
});

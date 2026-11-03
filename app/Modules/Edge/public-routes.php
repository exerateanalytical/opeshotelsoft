<?php

use App\Modules\Edge\Http\EdgeSyncController;
use Illuminate\Support\Facades\Route;

// Edge-node sync API: signed edge tokens (X-Edge-Node / X-Edge-Timestamp / X-Edge-Signature).
Route::prefix('edge/sync')->middleware('throttle:api')->group(function () {
    Route::post('push', [EdgeSyncController::class, 'push']);
    Route::post('pull', [EdgeSyncController::class, 'pull']);
});

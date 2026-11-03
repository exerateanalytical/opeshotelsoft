<?php

use App\Modules\Insights\Http\InsightsController as I;
use Illuminate\Support\Facades\Route;

Route::prefix('insights')->group(function () {
    Route::get('owner', [I::class, 'owner']);
    Route::get('group', [I::class, 'group']);
    Route::get('alerts', [I::class, 'alerts']);
    Route::get('alerts/{alert}', [I::class, 'show'])->whereNumber('alert');
    Route::post('alerts/{alert}/acknowledge', [I::class, 'acknowledge'])->whereNumber('alert');
    Route::post('alerts/{alert}/resolve', [I::class, 'resolve'])->whereNumber('alert');
    Route::get('settings', [I::class, 'settings']);
    Route::put('settings', [I::class, 'updateSettings']);
    Route::post('scan', [I::class, 'scan']);
    Route::post('digest/send', [I::class, 'sendDigest']);
    Route::get('digests', [I::class, 'digests']);
});

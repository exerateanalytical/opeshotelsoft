<?php

use App\Modules\ServiceRequests\Http\ServiceRequestController as S;
use Illuminate\Support\Facades\Route;

Route::prefix('service-requests')->group(function () {
    Route::get('feed', [S::class, 'feed']);
    Route::get('catalog', [S::class, 'catalog']);
    Route::get('settings', [S::class, 'settings']);
    Route::put('settings', [S::class, 'updateSettings']);
    Route::get('voice-settings', [S::class, 'voiceSettings']);
    Route::put('voice-settings', [S::class, 'updateVoiceSettings']);
    Route::post('escalate', [S::class, 'escalate']);
    Route::get('/', [S::class, 'index']);
    Route::post('/', [S::class, 'store']);
    Route::get('{serviceRequest}', [S::class, 'show'])->whereNumber('serviceRequest');
    Route::post('{serviceRequest}/acknowledge', [S::class, 'acknowledge'])->whereNumber('serviceRequest');
    Route::post('{serviceRequest}/assign', [S::class, 'assign'])->whereNumber('serviceRequest');
    Route::post('{serviceRequest}/start', [S::class, 'start'])->whereNumber('serviceRequest');
    Route::post('{serviceRequest}/complete', [S::class, 'complete'])->whereNumber('serviceRequest');
    Route::post('{serviceRequest}/cancel', [S::class, 'cancel'])->whereNumber('serviceRequest');
});

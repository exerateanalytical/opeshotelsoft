<?php

use App\Modules\Reservations\Experience\Http\ExperienceController as E;
use App\Modules\Reservations\Experience\Http\TapeController as T;
use Illuminate\Support\Facades\Route;

// R-B reservation experience API (included by Reservations/routes.php, mounted under /api/v1). Own prefix so it never
// collides with reservations/{reservation}.
Route::prefix('experience')->group(function () {
    Route::middleware('can:experience.view')->group(function () {
        Route::get('arrivals', [E::class, 'arrivals']);
        Route::get('reservations/{reservation}/card', [E::class, 'card']);
        Route::get('bookings/{ref}', [E::class, 'booking']);
        Route::get('rooming/template', [E::class, 'roomingTemplate']);
        Route::get('channels/health', [E::class, 'channelHealth']);
        Route::get('settings', [E::class, 'settings']);
        Route::get('services', [E::class, 'services']);
        Route::get('reviews', [E::class, 'reviews']);
        Route::get('fee-rules', [E::class, 'feeRules']);
    });
    Route::middleware('can:experience.manage')->group(function () {
        Route::post('tape/move', [T::class, 'move']);
        Route::post('tape/resize', [T::class, 'resize']);
        Route::post('tape/split', [T::class, 'split']);
        Route::post('tape/swap', [T::class, 'swap']);
        Route::post('bookings', [E::class, 'book']);
        Route::post('reservations/{reservation}/checkin-data', [E::class, 'captureCheckin']);
        Route::patch('reservations/{reservation}/preferences', [E::class, 'preferences']);
        Route::post('suggestions/generate', [E::class, 'suggest']);
        Route::post('suggestions/{suggestion}/accept', [E::class, 'accept']);
        Route::post('suggestions/{suggestion}/reject', [E::class, 'reject']);
        Route::post('blocks/{block}/rooming-import', [E::class, 'roomingImport']);
        Route::post('channels/{connection}/retry', [E::class, 'channelRetry']);
        Route::put('settings', [E::class, 'saveSettings']);
        Route::post('services', [E::class, 'saveService']);
        Route::patch('services/{service}', [E::class, 'saveService']);
        Route::post('fee-rules', [E::class, 'saveFeeRule']);
        Route::patch('fee-rules/{rule}', [E::class, 'saveFeeRule']);
    });
});

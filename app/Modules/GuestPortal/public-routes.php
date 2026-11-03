<?php

use App\Modules\GuestPortal\Http\BookingEngineController as B;
use Illuminate\Support\Facades\Route;

// Public Booking API (website, guest app). Group-scoped by slug, rate limited.
Route::prefix('booking/{group}')->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('hotels', [B::class, 'hotels']);
        Route::get('availability', [B::class, 'availability']);
    });
    Route::middleware('throttle:public-write')->group(function () {
        Route::post('reservations', [B::class, 'book']);
        Route::post('reservations/lookup', [B::class, 'lookup']);
        Route::post('reservations/cancel', [B::class, 'cancel']);
        Route::post('reservations/check-in', [B::class, 'checkIn']);
    });
});

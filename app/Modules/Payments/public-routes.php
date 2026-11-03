<?php

use App\Modules\Payments\Http\PublicPaymentController as C;
use Illuminate\Support\Facades\Route;

// Provider callbacks: no login, authenticated by each provider's signature. MTN uses PUT.
Route::match(['post', 'put'], 'payments/callbacks/{provider}/{reference}', [C::class, 'callback'])
    ->middleware('throttle:120,1')->whereUuid('reference');

// Booking-site deposit, authenticated by the manage-booking token.
Route::prefix('booking/{group}/reservations/{confirmation}')->group(function () {
    Route::post('deposit', [C::class, 'deposit'])->middleware('throttle:public-write');
    Route::get('deposit/{reference}', [C::class, 'status'])->middleware('throttle:public-read')->whereUuid('reference');
});

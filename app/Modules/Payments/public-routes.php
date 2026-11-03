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

// Q5 guest pay page (pay link + QR), authenticated by the link's short signature.
use App\Modules\Payments\Http\PublicPayLinkController as PL;

Route::prefix('pay/{code}/{sig}')->where(['code' => '[A-Z0-9]{6,16}', 'sig' => '[a-f0-9]{10}'])->group(function () {
    Route::get('/', [PL::class, 'show'])->middleware('throttle:public-read');
    Route::post('intents', [PL::class, 'pay'])->middleware('throttle:public-write');
    Route::get('intents/{reference}', [PL::class, 'status'])->middleware('throttle:public-read')->whereUuid('reference');
});
// Inbound MoMo notifications (money received without a started intent), HMAC-signed.
Route::post('payments/inbound/{provider}/{hotel}', [PL::class, 'inbound'])->middleware('throttle:120,1')->whereNumber('hotel');

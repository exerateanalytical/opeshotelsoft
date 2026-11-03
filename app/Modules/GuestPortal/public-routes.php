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

// Q6 in-stay ordering from the signed WhatsApp link (signature = credential).
Route::post('guest/order/{reservation}', [\App\Modules\GuestPortal\Http\GuestOrderController::class, 'place'])
    ->middleware(['signed', 'throttle:public-write'])->whereNumber('reservation')->name('guest.order.place');

// R-B guest self-service behind the manage-booking page (manage token = credential).
Route::prefix('booking/{group}/manage')->middleware('throttle:public-write')->group(function () {
    $c = \App\Modules\GuestPortal\Http\ManageController::class;
    Route::post('options', [$c, 'options']);
    Route::post('modify', [$c, 'modify']);
    Route::post('services', [$c, 'service']);
    Route::post('timing', [$c, 'timing']);
    Route::post('pay', [$c, 'pay']);
    Route::post('checkin', [$c, 'checkin']);
    Route::post('review', [$c, 'review']);
});

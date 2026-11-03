<?php

use App\Modules\Reservations\Revenue\Http\PublicUpsellController;
use Illuminate\Support\Facades\Route;

// R-A guest-facing upgrade offers (confirmation number + manage token). Group-scoped by slug.
Route::prefix('booking/{group}/upsell')->middleware('throttle:public-write')->group(function () {
    Route::post('offers', [PublicUpsellController::class, 'offers']);
    Route::post('accept', [PublicUpsellController::class, 'accept']);
    Route::post('decline', [PublicUpsellController::class, 'decline']);
});

<?php

use App\Modules\Loyalty\Http\LoyaltyController as L;
use Illuminate\Support\Facades\Route;

Route::prefix('loyalty')->group(function () {
    Route::get('program', [L::class, 'program']);
    Route::put('program', [L::class, 'setup']);
    Route::get('members', [L::class, 'members']);
    Route::post('members', [L::class, 'enrol']);
    Route::get('members/{member}', [L::class, 'show']);
    Route::post('members/{member}/cards', [L::class, 'issueCard']);
    Route::post('members/{member}/redeem', [L::class, 'redeem']);
    Route::post('members/{member}/adjust', [L::class, 'adjust']);
    Route::post('cards/lookup', [L::class, 'lookup']);
});

Route::prefix('loyalty')->group(function () {
    Route::get('tiers', [L::class, 'tiers']);
    Route::post('tiers', [L::class, 'storeTier']);
    Route::patch('tiers/{tier}', [L::class, 'updateTier']);
});

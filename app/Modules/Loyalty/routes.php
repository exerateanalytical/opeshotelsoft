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

Route::prefix('loyalty')->controller(App\Modules\Loyalty\Http\LoyaltyCardController::class)->group(function () {
    Route::get('cards/{card}', 'card')->whereNumber('card');
    Route::get('cards/{card}/pdf', 'cardPdf')->whereNumber('card');
    Route::post('cards/{card}/block', 'block')->whereNumber('card');
    Route::post('cards/{card}/unblock', 'unblock')->whereNumber('card');
    Route::post('cards/{card}/replace', 'replace')->whereNumber('card');
    Route::get('promotions', 'promotions');
    Route::post('promotions', 'storePromotion');
    Route::patch('promotions/{promotion}', 'updatePromotion');
    Route::get('members/{member}/statement', 'statement');
    Route::get('members/{member}/statement/pdf', 'statementPdf');
    Route::post('members/{member}/statement/email', 'emailStatement');
    Route::post('pos/checks/{check}/member', 'attachToCheck');
});

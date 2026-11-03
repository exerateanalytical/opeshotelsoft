<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// R-A booking revenue engine screens.
Route::middleware(['auth'])->group(function () {
    Route::get('/revenue/suggestions', fn () => Inertia::render('Reservations/RevenueSuggestions'))->middleware('can:revenue.view')->name('revenue.suggestions');
    Route::get('/revenue/rules', fn () => Inertia::render('Reservations/RevenueRules'))->middleware('can:revenue.view')->name('revenue.rules');
    Route::get('/revenue/offers', fn () => Inertia::render('Reservations/RevenueOffers'))->middleware('can:revenue.view')->name('revenue.offers');
    Route::get('/revenue/overbooking', fn () => Inertia::render('Reservations/RevenueOverbooking'))->middleware('can:revenue.view')->name('revenue.overbooking');
});

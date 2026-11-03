<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Q4 owner view and leakage alerts.
Route::middleware(['auth'])->group(function () {
    Route::get('/owner', fn () => Inertia::render('Insights/Owner'))->middleware('can:insights.view')->name('insights.owner');
    Route::get('/owner/group', fn () => Inertia::render('Insights/Group'))->middleware('can:insights.group')->name('insights.group');
    Route::get('/insights/alerts', fn () => Inertia::render('Insights/Alerts'))->middleware('can:insights.view')->name('insights.alerts');
    Route::get('/insights/settings', fn () => Inertia::render('Insights/Settings'))->middleware('can:insights.manage')->name('insights.settings');
});

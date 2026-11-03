<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Q1 onboarding & branding screens.
Route::middleware(['auth'])->group(function () {
    Route::get('/onboarding', fn () => Inertia::render('Onboarding/Wizard'))->middleware('can:onboarding.manage')->name('onboarding.wizard');
    Route::get('/onboarding/import', fn () => Inertia::render('Onboarding/Import'))->middleware('can:onboarding.manage')->name('onboarding.import');
    Route::get('/onboarding/branding', fn () => Inertia::render('Onboarding/Branding'))->middleware('can:branding.manage')->name('onboarding.branding');
    Route::get('/onboarding/templates', fn () => Inertia::render('Onboarding/Templates'))->middleware('can:branding.manage')->name('onboarding.templates');
});

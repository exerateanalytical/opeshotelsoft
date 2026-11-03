<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// H-A workforce screens. Self-service pages (/hr/workforce/me, /hr/workforce/clock) need only a login.
Route::middleware(['auth'])->prefix('hr/workforce')->group(function () {
    Route::get('/', fn () => Inertia::render('HR/WorkforceRoster'))->middleware('can:workforce.view')->name('workforce.roster');
    Route::get('/requests', fn () => Inertia::render('HR/WorkforceRequests'))->name('workforce.requests');
    Route::get('/time', fn () => Inertia::render('HR/WorkforceTime'))->middleware('can:workforce.view')->name('workforce.time');
    Route::get('/leave', fn () => Inertia::render('HR/WorkforceLeave'))->middleware('can:workforce.view')->name('workforce.leave');
    Route::get('/cost', fn () => Inertia::render('HR/WorkforceCost'))->middleware('can:workforce.view')->name('workforce.cost');
    Route::get('/clock', fn () => Inertia::render('HR/WorkforceClock'))->name('workforce.clock');
});

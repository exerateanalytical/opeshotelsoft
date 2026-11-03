<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// W11 HR extras: standalone org chart page (the HR index also has an "Org chart" tab).
Route::middleware(['auth'])->group(function () {
    Route::get('/hr/org-chart', fn () => Inertia::render('HR/OrgChart'))->middleware('can:hr.view')->name('hr.org-chart');
});

// Q2 employment lifecycle: staff screen, and the public phone signing page (token-protected, no login).
Route::middleware(['auth'])->group(function () {
    Route::get('/hr/lifecycle', fn () => Inertia::render('HR/Lifecycle'))->middleware('can:hr.view')->name('hr.lifecycle');
});
Route::get('/sign/contract/{token}', fn (string $token) => Inertia::render('HR/SignContract', ['token' => $token]))
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:public-read')->name('hr.sign-contract');

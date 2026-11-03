<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// V1 service requests board, voice settings.
Route::middleware(['auth'])->group(function () {
    Route::get('/service-requests', fn () => Inertia::render('ServiceRequests/Board'))->middleware('can:servicerequests.view')->name('servicerequests.board');
    Route::get('/service-requests/settings', fn () => Inertia::render('ServiceRequests/Settings'))->middleware('can:servicerequests.view')->name('servicerequests.settings');
});

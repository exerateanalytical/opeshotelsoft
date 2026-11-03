<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

$desk = PermissionMiddleware::class.':frontdesk.view|frontdesk.manage';

// W07 front desk extras.
Route::middleware(['auth'])->group(function () use ($desk) {
    Route::get('/front-desk/in-house', fn () => Inertia::render('FrontDesk/InHouse'))->middleware($desk)->name('front-desk.in-house');
});

<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

$resv = PermissionMiddleware::class.':reservations.view|reservations.manage';
$rates = PermissionMiddleware::class.':rates.view|rates.manage';

// W06 reservations extras. NOTE: routes/web.php's /reservations/{id} must be ->whereNumber('id')
// (it is registered first), otherwise it shadows these static /reservations/* paths.
Route::middleware(['auth'])->group(function () use ($resv, $rates) {
    Route::get('/reservations/tape-chart', fn () => Inertia::render('Reservations/TapeChart'))->middleware($resv)->name('reservations.tape-chart');
    Route::get('/reservations/waitlist', fn () => Inertia::render('Reservations/Waitlist'))->middleware($resv)->name('reservations.waitlist');
    Route::get('/reservations/travel-agents', fn () => Inertia::render('Reservations/TravelAgents'))->middleware($resv)->name('reservations.travel-agents');
    Route::get('/reservations/seasons', fn () => Inertia::render('Reservations/Seasons'))->middleware($rates)->name('reservations.seasons');
});

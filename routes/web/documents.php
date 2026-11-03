<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

// W05 documents and exports.
Route::middleware(['auth'])->group(function () {
    Route::get('/documents/scheduled-reports', fn () => Inertia::render('Documents/ScheduledReports'))
        ->middleware(PermissionMiddleware::class.':reports.view|reports.manage')->name('documents.scheduled-reports');
});

// D-A: public verification page (QR target). No login, rate limited.
Route::get('/verify/{code}', [\App\Modules\Documents\Http\VerifyController::class, 'page'])
    ->where('code', '[0-9A-Za-z_-]{1,40}')->middleware('throttle:verify')->name('documents.verify');

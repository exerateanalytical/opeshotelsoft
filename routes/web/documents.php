<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

// W05 documents and exports.
Route::middleware(['auth'])->group(function () {
    Route::get('/documents/scheduled-reports', fn () => Inertia::render('Documents/ScheduledReports'))
        ->middleware(PermissionMiddleware::class.':reports.view|reports.manage')->name('documents.scheduled-reports');
});

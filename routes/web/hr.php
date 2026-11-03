<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// W11 HR extras: standalone org chart page (the HR index also has an "Org chart" tab).
Route::middleware(['auth'])->group(function () {
    Route::get('/hr/org-chart', fn () => Inertia::render('HR/OrgChart'))->middleware('can:hr.view')->name('hr.org-chart');
});

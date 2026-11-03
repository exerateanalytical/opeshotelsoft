<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// F1 Fast Configure.
Route::middleware(['auth'])->group(function () {
    Route::get('/properties/fast-configure', fn () => Inertia::render('Property/FastConfigure'))->middleware('can:property.fast_configure')->name('property.fast_configure');
});

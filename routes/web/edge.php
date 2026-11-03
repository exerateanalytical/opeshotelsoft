<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// P6 hotel edge node: sync status screen.
Route::middleware(['auth'])->group(function () {
    Route::get('/edge', fn () => Inertia::render('Edge/Status'))->middleware('can:edge.manage')->name('edge.status');
});

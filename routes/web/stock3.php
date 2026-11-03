<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// I-B inventory operations screens (data from /api/v1/stock-ops).
Route::middleware(['auth'])->prefix('stock')->name('stock3.')->group(function () {
    Route::get('/rfq', fn () => Inertia::render('Stock/Rfq'))->middleware('can:stockops.view')->name('rfq');
    Route::get('/scan', fn () => Inertia::render('Stock/Scan'))->middleware('can:stockops.scan')->name('scan');
    Route::get('/stocktake', fn () => Inertia::render('Stock/Stocktake'))->middleware('can:stocktake.count')->name('stocktake');
    Route::get('/minibar', fn () => Inertia::render('Stock/Minibar'))->middleware('can:stockops.minibar')->name('minibar');
    Route::get('/menu-engineering', fn () => Inertia::render('Stock/MenuEngineering'))->middleware('can:stockops.view')->name('menu-engineering');
    Route::get('/issues', fn () => Inertia::render('Stock/Issues'))->middleware('can:stockops.view')->name('issues');
});

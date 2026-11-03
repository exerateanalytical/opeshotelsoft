<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// I-A inventory control screens (data from /api/v1/stock-control).
Route::middleware(['auth'])->prefix('stock')->name('stock2.')->group(function () {
    Route::get('/recipes', fn () => Inertia::render('Stock/Recipes'))->middleware('can:stockcontrol.view')->name('recipes');
    Route::get('/variance', fn () => Inertia::render('Stock/Variance'))->middleware('can:stockcontrol.view')->name('variance');
    Route::get('/reorder', fn () => Inertia::render('Stock/Reorder'))->middleware('can:stockcontrol.view')->name('reorder');
    Route::get('/batches', fn () => Inertia::render('Stock/Batches'))->middleware('can:stockcontrol.view')->name('batches');
    Route::get('/valuation', fn () => Inertia::render('Stock/Valuation'))->middleware('can:stockcontrol.valuation')->name('valuation');
});

<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A-A core ledger screens. Each page carries the guard matching its API permission.
Route::middleware(['auth'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/budgets', fn () => Inertia::render('Finance/Budgets'))->middleware('can:accounting.view')->name('budgets');
    Route::get('/dimensions', fn () => Inertia::render('Finance/Dimensions'))->middleware('can:accounting.view')->name('dimensions');
    Route::get('/accruals', fn () => Inertia::render('Finance/Accruals'))->middleware('can:accounting.view')->name('accruals');
    Route::get('/consolidation', fn () => Inertia::render('Finance/Consolidation'))->middleware('can:accounting.consolidate')->name('consolidation');
});

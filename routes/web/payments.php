<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->prefix('payments')->name('payments.')->group(function () {
    Route::get('/', fn () => Inertia::render('Payments/Index'))->middleware('can:payments.view')->name('index');
    Route::get('/gateways', fn () => Inertia::render('Payments/Gateways'))->middleware('can:payments.settings')->name('gateways');
    Route::get('/{id}', fn ($id) => Inertia::render('Payments/Show', compact('id')))->whereNumber('id')->middleware('can:payments.view')->name('show');
});

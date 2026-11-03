<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->prefix('payments')->name('payments.')->group(function () {
    Route::get('/', fn () => Inertia::render('Payments/Index'))->middleware('can:payments.view')->name('index');
    Route::get('/gateways', fn () => Inertia::render('Payments/Gateways'))->middleware('can:payments.settings')->name('gateways');
    Route::get('/{id}', fn ($id) => Inertia::render('Payments/Show', compact('id')))->whereNumber('id')->middleware('can:payments.view')->name('show');
});

// Q5 staff screens.
Route::middleware(['auth'])->prefix('payments')->name('payments.')->group(function () {
    Route::get('/links', fn () => Inertia::render('Payments/Links'))->middleware('can:payments.links')->name('links');
    Route::get('/inbound', fn () => Inertia::render('Payments/Inbound'))->middleware('can:payments.reconcile')->name('inbound');
    Route::get('/payouts', fn () => Inertia::render('Payments/Payouts'))->middleware('can:payments.payouts')->name('payouts');
    Route::get('/payouts/{id}', fn ($id) => Inertia::render('Payments/PayoutShow', ['id' => (int) $id]))->whereNumber('id')->middleware('can:payments.payouts')->name('payouts.show');
});

// Q5 guest pay page (no login; authorised by the link signature).
Route::get('/pay/{code}/{sig}', fn (string $code, string $sig) => Inertia::render('Payments/PayLink', [
    'code' => $code, 'sig' => $sig, 'lang' => in_array(request('lang'), ['fr', 'en'], true) ? request('lang') : 'fr',
]))->where(['code' => '[A-Z0-9]{6,16}', 'sig' => '[a-f0-9]{10}'])->middleware('throttle:public-read')->name('payments.pay');

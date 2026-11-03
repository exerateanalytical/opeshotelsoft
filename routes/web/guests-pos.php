<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// W08 guests/CRM and POS extras.
Route::middleware(['auth'])->group(function () {
    Route::get('/guests/privacy-requests', fn () => Inertia::render('Guests/PrivacyRequests'))
        ->middleware('can:guests.manage')->name('guests.privacy-requests');
    Route::get('/guests/companies/{id}', fn ($id) => Inertia::render('Guests/CompanyShow', compact('id')))
        ->whereNumber('id')->middleware('can:guests.view')->name('guests.companies.show');
    Route::get('/guests/{id}', fn ($id) => Inertia::render('Guests/Show', compact('id')))
        ->whereNumber('id')->middleware('can:guests.view')->name('guests.show');
    Route::get('/pos/checks/{id}/kot', fn (Request $request, $id) => Inertia::render('POS/Kot', ['id' => $id, 'firedAt' => $request->query('fired_at')]))
        ->whereNumber('id')->middleware('can:pos.view')->name('pos.kot');
});

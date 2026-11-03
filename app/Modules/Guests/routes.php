<?php

use App\Modules\Guests\Http\CompanyController;
use App\Modules\Guests\Http\GuestController;
use App\Modules\Guests\Http\GuestProfileController as P;
use Illuminate\Support\Facades\Route;

Route::get('guests/privacy-requests', [P::class, 'privacyRequests']);
Route::post('guests/privacy-requests/{req}/approve', [P::class, 'approvePrivacy']);
Route::post('guests/privacy-requests/{req}/reject', [P::class, 'rejectPrivacy']);
Route::get('guests/{guest}/duplicates', [GuestController::class, 'duplicates']);
Route::post('guests/{guest}/merge', [GuestController::class, 'merge']);
Route::get('guests/{guest}/export', [GuestController::class, 'export']);
Route::get('guests/{guest}/history', [P::class, 'history']);
Route::get('guests/{guest}/folios', [P::class, 'folios']);
Route::get('guests/{guest}/loyalty', [P::class, 'loyalty']);
Route::post('guests/{guest}/privacy-requests', [P::class, 'requestPrivacy']);
Route::apiResource('guests', GuestController::class)->except('destroy');
Route::get('companies/{company}/overview', [P::class, 'company']);
Route::post('companies/{company}/contacts', [P::class, 'storeContact']);
Route::patch('companies/{company}/contacts/{contact}', [P::class, 'updateContact']);
Route::delete('companies/{company}/contacts/{contact}', [P::class, 'destroyContact']);
Route::apiResource('companies', CompanyController::class);

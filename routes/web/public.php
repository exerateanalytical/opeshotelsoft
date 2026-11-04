<?php

use App\Http\Controllers\PublicGroupPageController as P;
use Illuminate\Support\Facades\Route;

/*
| Public guest booking website (W02). No login: data comes from the public
| Booking API (/api/v1/booking/{slug}/...), authorised per booking by the
| manage token returned at lookup. Language via ?lang=fr|en.
| Controller actions (not closure factories) so `php artisan route:cache` works.
*/
Route::prefix('book/{group}')->middleware('throttle:public-read')->name('public.')->group(function () {
    Route::get('/', [P::class, 'hotels'])->name('hotels');
    Route::get('/search', [P::class, 'search'])->name('search');
    Route::get('/details', [P::class, 'details'])->name('details');
    Route::get('/confirmation', [P::class, 'confirmation'])->name('confirmation');
    Route::get('/manage', [P::class, 'manage'])->name('manage');
});

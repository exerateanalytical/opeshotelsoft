<?php

use App\Modules\HR\Talent\Http\CareersController as C;
use Illuminate\Support\Facades\Route;

// Public careers site and offer replies (no login).
Route::prefix('careers')->group(function () {
    Route::get('offers/{token}', [C::class, 'offer'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:public-read');
    Route::post('offers/{token}/accept', [C::class, 'accept'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:public-write');
    Route::post('offers/{token}/decline', [C::class, 'decline'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:public-write');
    Route::get('{group}', [C::class, 'index'])->middleware('throttle:public-read');
    Route::post('{group}/posts/{post}/apply', [C::class, 'apply'])->whereNumber('post')->middleware('throttle:public-write');
});

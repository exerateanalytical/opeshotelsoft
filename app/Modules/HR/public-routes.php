<?php

use App\Modules\HR\Http\ContractSigningController as S;
use Illuminate\Support\Facades\Route;

// Q2 e-signature from the employee's phone. The unguessable link token is the credential; OTP proves the phone/email.
Route::prefix('hr/sign/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->group(function () {
    Route::get('/', [S::class, 'show'])->middleware('throttle:public-read');
    Route::post('otp', [S::class, 'otp'])->middleware('throttle:public-write');
    Route::post('/', [S::class, 'sign'])->middleware('throttle:public-write');
});

require __DIR__.'/Talent/public-routes.php';

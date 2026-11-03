<?php

use App\Modules\Admin\Http\TokenController;
use Illuminate\Support\Facades\Route;

// Token issuance; credential brute force is also limited per email+IP by LoginGuard.
Route::post('auth/token', [TokenController::class, 'store'])->middleware('throttle:auth-token');
Route::post('auth/token/two-factor', [TokenController::class, 'twoFactor'])->middleware('throttle:auth-token');

<?php

use App\Modules\Documents\Http\VerifyController;
use Illuminate\Support\Facades\Route;

// D-A: public JSON verification of an issued document (same data as the /verify page).
Route::get('verify/{code}', [VerifyController::class, 'json'])->middleware('throttle:verify');

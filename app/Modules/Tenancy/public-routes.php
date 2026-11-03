<?php

use App\Modules\Tenancy\Http\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:public-write');

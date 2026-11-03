<?php

use App\Modules\Tenancy\Http\AuthController;
use App\Modules\Tenancy\Http\HotelController;
use App\Modules\Tenancy\Http\StaffController;
use App\Modules\Tenancy\Http\UserController;
use Illuminate\Support\Facades\Route;

Route::get('me', [AuthController::class, 'me']);
Route::post('auth/logout', [AuthController::class, 'logout']);

Route::apiResource('hotels', HotelController::class)->except('destroy');
Route::get('staff', [StaffController::class, 'index']);
Route::get('users', [UserController::class, 'index']);
Route::post('users', [UserController::class, 'store']);
Route::patch('users/{user}', [UserController::class, 'update']);
Route::get('roles', [UserController::class, 'roles']);

<?php

use App\Modules\Property\Http\AssetController;
use App\Modules\Property\Http\OutletController;
use App\Modules\Property\Http\RoomController;
use App\Modules\Property\Http\RoomTypeController;
use Illuminate\Support\Facades\Route;

Route::post('rooms/bulk', [RoomController::class, 'bulk']);
Route::apiResource('room-types', RoomTypeController::class);
Route::apiResource('rooms', RoomController::class);
Route::apiResource('outlets', OutletController::class);
Route::apiResource('assets', AssetController::class);

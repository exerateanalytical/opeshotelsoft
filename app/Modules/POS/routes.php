<?php

use App\Modules\POS\Http\MenuItemController;
use App\Modules\POS\Http\PosController;
use App\Modules\POS\Http\PosTableController;
use App\Modules\POS\Http\PriceListController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->group(function () {
    Route::apiResource('menu-items', MenuItemController::class);
    Route::apiResource('tables', PosTableController::class);
    Route::apiResource('price-lists', PriceListController::class);
    Route::get('outlets/{outlet}/floor', [PosController::class, 'floor']);
    Route::post('tables/{table}/check', [PosController::class, 'openAtTable']);
    Route::get('checks', [PosController::class, 'index']);
    Route::post('checks', [PosController::class, 'store']);
    Route::get('checks/{check}', [PosController::class, 'show']);
    Route::get('checks/{check}/tickets', [PosController::class, 'tickets']);
    Route::post('checks/{check}/transfer', [PosController::class, 'transfer']);
    Route::post('checks/{check}/comp', [PosController::class, 'comp']);
    Route::post('checks/{check}/comp/decide', [PosController::class, 'decideComp']);
    Route::post('checks/{check}/items', [PosController::class, 'addItem']);
    Route::post('checks/{check}/fire', [PosController::class, 'fire']);
    Route::patch('checks/{check}/lines/{line}', [PosController::class, 'updateLine']);
    Route::post('checks/{check}/lines/{line}/remove', [PosController::class, 'removeLine']);
    Route::post('checks/{check}/lines/{line}/void', [PosController::class, 'voidLine']);
    Route::post('checks/{check}/discount', [PosController::class, 'discount']);
    Route::post('checks/{check}/split', [PosController::class, 'split']);
    Route::post('checks/{check}/settle', [PosController::class, 'settle']);
    Route::post('checks/{check}/charge-to-room', [PosController::class, 'chargeToRoom']);
    Route::post('checks/{check}/void', [PosController::class, 'voidCheck']);
    Route::post('sync', [PosController::class, 'sync']);
    Route::get('reports/day', [PosController::class, 'report']);
});

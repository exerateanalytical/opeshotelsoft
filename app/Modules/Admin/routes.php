<?php

use App\Modules\Admin\Http\AuditController;
use App\Modules\Admin\Http\CurrencyController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::get('audit', [AuditController::class, 'index']);
    Route::get('audit/filters', [AuditController::class, 'filters']);
    Route::get('currencies', [C::class, 'index']);
    Route::post('currencies', [C::class, 'store']);
    Route::patch('currencies/{code}', [C::class, 'update']);
    Route::delete('currencies/{code}', [C::class, 'destroy']);
    Route::get('exchange-rates', [C::class, 'rates']);
    Route::get('exchange-rates/lookup', [C::class, 'lookup']);
    Route::post('exchange-rates', [C::class, 'storeRate']);
    Route::delete('exchange-rates/{id}', [C::class, 'destroyRate'])->whereNumber('id');
});

<?php

use App\Modules\Payments\Http\PaymentController as P;
use Illuminate\Support\Facades\Route;

Route::get('payments/intents', [P::class, 'index']);
Route::post('payments/intents', [P::class, 'store']);
Route::get('payments/intents/{intent}', [P::class, 'show'])->whereNumber('intent');
Route::post('payments/intents/{intent}/refunds', [P::class, 'refund'])->whereNumber('intent');
Route::get('payments/gateways', [P::class, 'gateways']);
Route::put('payments/gateways/{provider}', [P::class, 'saveGateway']);

<?php

use App\Modules\Payments\Http\PaymentController as P;
use Illuminate\Support\Facades\Route;

Route::get('payments/intents', [P::class, 'index']);
Route::post('payments/intents', [P::class, 'store']);
Route::get('payments/intents/{intent}', [P::class, 'show'])->whereNumber('intent');
Route::post('payments/intents/{intent}/refunds', [P::class, 'refund'])->whereNumber('intent');
Route::get('payments/gateways', [P::class, 'gateways']);
Route::put('payments/gateways/{provider}', [P::class, 'saveGateway']);

// Q5: pay links + QR, inbound matching, salary payouts.
use App\Modules\Payments\Http\MobileMoneyController as MM;

Route::get('payments/links', [MM::class, 'links']);
Route::post('payments/links', [MM::class, 'storeLink']);
Route::get('payments/links/{link}', [MM::class, 'showLink'])->whereNumber('link');
Route::post('payments/links/{link}/cancel', [MM::class, 'cancelLink'])->whereNumber('link');
Route::get('payments/inbound', [MM::class, 'inbound']);
Route::post('payments/inbound/{inbound}/rematch', [MM::class, 'rematch'])->whereNumber('inbound');
Route::post('payments/inbound/{inbound}/post', [MM::class, 'postInbound'])->whereNumber('inbound');
Route::post('payments/inbound/{inbound}/ignore', [MM::class, 'ignoreInbound'])->whereNumber('inbound');
Route::post('payments/bank-accounts/{bank}/scan', [MM::class, 'scanStatement'])->whereNumber('bank');
Route::get('payments/payouts', [MM::class, 'payouts']);
Route::post('payments/payouts', [MM::class, 'storePayout']);
Route::get('payments/payouts/{payout}', [MM::class, 'showPayout'])->whereNumber('payout');
Route::post('payments/payouts/{payout}/process', [MM::class, 'processPayout'])->whereNumber('payout');
Route::get('payments/payouts/{payout}/bank-file', [MM::class, 'bankFile'])->whereNumber('payout');
Route::post('payments/payouts/{payout}/confirm', [MM::class, 'confirmPayout'])->whereNumber('payout');
Route::post('payments/payouts/{payout}/reject', [MM::class, 'rejectPayoutItems'])->whereNumber('payout');
Route::put('payments/payouts/{payout}/items/{item}', [MM::class, 'updatePayoutItem'])->whereNumber('payout')->whereNumber('item');

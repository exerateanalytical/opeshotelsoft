<?php

use App\Modules\FrontOffice\Http\CashierController;
use App\Modules\FrontOffice\Http\FolioController;
use App\Modules\FrontOffice\Http\FrontDeskController;
use App\Modules\FrontOffice\Http\FrontDeskExtrasController;
use App\Modules\FrontOffice\Http\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('front-desk', [FrontDeskController::class, 'dashboard']);
Route::post('reservations/{reservation}/check-in', [FrontDeskController::class, 'checkIn']);
Route::post('reservations/{reservation}/check-out', [FrontDeskController::class, 'checkOut']);
Route::post('reservations/{reservation}/move-room', [FrontDeskController::class, 'moveRoom']);
Route::post('walk-ins', [FrontDeskController::class, 'walkIn']);
Route::get('night-audit', [FrontDeskController::class, 'auditCheck']);
Route::post('night-audit', [FrontDeskController::class, 'audit']);
Route::get('business-days', [FrontDeskController::class, 'businessDays']);
Route::get('police-register', [FrontDeskController::class, 'policeRegister']);

Route::get('folios', [FolioController::class, 'index']);
Route::post('folios', [FolioController::class, 'store']);
Route::get('folios/{folio}', [FolioController::class, 'show']);
Route::get('reservations/{reservation}/folio', [FolioController::class, 'forReservation']);
Route::post('folios/{folio}/charges', [FolioController::class, 'charge']);
Route::post('folios/{folio}/payments', [FolioController::class, 'payment']);
Route::post('folios/{folio}/refunds', [FolioController::class, 'refund']);
Route::post('folios/{folio}/lines/{line}/reverse', [FolioController::class, 'reverse']);
Route::post('folios/{folio}/transfer', [FolioController::class, 'transfer']);
Route::post('folios/{folio}/city-ledger', [FolioController::class, 'cityLedger']);
Route::post('folios/{folio}/close', [FolioController::class, 'close']);
Route::post('folios/{folio}/invoice', [FolioController::class, 'invoice']);
Route::get('invoices', [FolioController::class, 'invoices']);
Route::get('invoices/{invoice}', [FolioController::class, 'showInvoice']);

Route::get('cashier-shifts', [CashierController::class, 'index']);
Route::get('cashier-shifts/current', [CashierController::class, 'current']);
Route::post('cashier-shifts', [CashierController::class, 'open']);
Route::post('cashier-shifts/{shift}/close', [CashierController::class, 'close']);

Route::get('setup/{kind}', [SetupController::class, 'index'])->whereIn('kind', ['tax-codes', 'charge-codes', 'payment-methods']);
Route::put('setup/{kind}', [SetupController::class, 'upsert'])->whereIn('kind', ['tax-codes', 'charge-codes', 'payment-methods']);

// Phase 2 (W07): in-house list, check-out fees, room move picker, folio routing, registration card.
Route::get('front-desk/in-house', [FrontDeskExtrasController::class, 'inHouse']);
Route::get('front-desk/checkout-fee-rules', [FrontDeskExtrasController::class, 'feeRules']);
Route::put('front-desk/checkout-fee-rules', [FrontDeskExtrasController::class, 'saveFeeRules']);
Route::get('reservations/{reservation}/vacant-rooms', [FrontDeskExtrasController::class, 'vacantRooms']);
Route::get('reservations/{reservation}/check-out/quote', [FrontDeskExtrasController::class, 'checkoutQuote']);
Route::post('reservations/{reservation}/check-out/fee', [FrontDeskExtrasController::class, 'postCheckoutFee']);
Route::get('reservations/{reservation}/routing-rules', [FrontDeskExtrasController::class, 'routingRules']);
Route::post('reservations/{reservation}/routing-rules', [FrontDeskExtrasController::class, 'addRoutingRule']);
Route::delete('reservations/{reservation}/routing-rules/{rule}', [FrontDeskExtrasController::class, 'deleteRoutingRule']);
Route::get('reservations/{reservation}/registration-card', [FrontDeskExtrasController::class, 'registrationCard']);
Route::post('reservations/{reservation}/registration-card', [FrontDeskExtrasController::class, 'saveRegistrationCard']);
Route::get('reservations/{reservation}/registration-card/id-photo', [FrontDeskExtrasController::class, 'idPhoto']);
Route::get('reservations/{reservation}/registration-card/signature', [FrontDeskExtrasController::class, 'signature']);

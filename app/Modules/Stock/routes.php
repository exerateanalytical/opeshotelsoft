<?php

use App\Modules\Stock\Http\ProcurementController as P;
use App\Modules\Stock\Http\PurchasingExtrasController as X;
use App\Modules\Stock\Http\StockController as S;
use App\Modules\Stock\Http\StockItemController;
use App\Modules\Stock\Http\StoreController;
use App\Modules\Stock\Http\SupplierController;
use Illuminate\Support\Facades\Route;

Route::apiResource('suppliers', SupplierController::class);

Route::prefix('stock')->group(function () {
    Route::apiResource('items', StockItemController::class);
    Route::apiResource('stores', StoreController::class);
    Route::get('balances', [S::class, 'balances']);
    Route::get('movements', [S::class, 'movements']);
    Route::post('transfers', [S::class, 'transfer']);
    Route::post('issues', [S::class, 'issue']);
    Route::get('wastage-reasons', [X::class, 'wastageReasons']);
    Route::get('reports/wastage', [X::class, 'wastageReport']);
    Route::get('reorder', [S::class, 'reorder']);
    Route::get('counts', [S::class, 'counts']);
    Route::get('counts/{count}', [S::class, 'showCount']);
    Route::post('counts', [S::class, 'startCount']);
    Route::put('counts/{count}', [S::class, 'recordCount']);
    Route::post('counts/{count}/post', [S::class, 'postCount']);
});

Route::prefix('purchasing')->group(function () {
    Route::get('orders', [P::class, 'orders']);
    Route::post('orders', [P::class, 'createOrder']);
    Route::get('orders/{order}', [P::class, 'showOrder']);
    Route::post('orders/{order}/submit', [P::class, 'submit']);
    Route::post('orders/{order}/approve', [P::class, 'approve']);
    Route::post('orders/{order}/cancel', [P::class, 'cancel']);
    Route::post('orders/{order}/receive', [P::class, 'receive']);
    Route::get('receipts', [P::class, 'receipts']);
    Route::post('receipts/{receipt}/returns', [X::class, 'createReturn']);
    Route::get('returns', [X::class, 'returns']);
    Route::get('requisitions', [X::class, 'requisitions']);
    Route::post('requisitions', [X::class, 'createRequisition']);
    Route::post('requisitions/convert', [X::class, 'convertRequisitions']);
    Route::post('requisitions/{requisition}/submit', [X::class, 'submitRequisition']);
    Route::post('requisitions/{requisition}/approve', [X::class, 'approveRequisition']);
    Route::post('requisitions/{requisition}/reject', [X::class, 'rejectRequisition']);
    Route::get('prices', [X::class, 'prices']);
    Route::get('prices/lookup', [X::class, 'lookupPrice']);
    Route::post('prices', [X::class, 'storePrice']);
    Route::put('prices/{price}', [X::class, 'updatePrice']);
    Route::delete('prices/{price}', [X::class, 'destroyPrice']);
});

Route::prefix('payables')->group(function () {
    Route::get('payment-accounts', [P::class, 'paymentAccounts']);
    Route::get('invoices', [P::class, 'invoices']);
    Route::get('invoices/due', [X::class, 'dueInvoices']);
    Route::get('payment-runs', [X::class, 'paymentRuns']);
    Route::post('payment-runs', [X::class, 'createPaymentRun']);
    Route::get('ageing', [X::class, 'ageing']);
    Route::post('credit-notes/{return}/apply', [X::class, 'applyCredit']);
    Route::post('invoices', [P::class, 'recordInvoice']);
    Route::post('invoices/{invoice}/approve', [P::class, 'approveInvoice']);
    Route::post('invoices/{invoice}/pay', [P::class, 'payInvoice']);
});

// I-A inventory control (recipes, variance, par levels, lots, valuation).
require __DIR__.'/Control/routes.php';

// I-B inventory operations (RFQ, scanning, stocktake, minibar, menu engineering, issues).
require __DIR__.'/Operations/routes.php';

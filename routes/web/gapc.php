<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// GAPC: purchase order detail page (data from /api/v1/purchasing/orders/{order}, PDF from /api/v1/documents/purchase-orders/{order}).
Route::middleware(['auth'])->get('/purchasing/orders/{order}', function (Request $request, int $order) {
    $u = $request->user();
    abort_unless($u->can('stock.view') || $u->can('purchasing.view') || $u->can('purchasing.manage'), 403);

    return Inertia::render('Purchasing/OrderShow', ['orderId' => $order]);
})->whereNumber('order')->name('purchasing.orders.show');

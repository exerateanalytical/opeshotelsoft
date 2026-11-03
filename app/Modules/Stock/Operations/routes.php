<?php

use App\Modules\Stock\Operations\Http\OperationsController as O;
use Illuminate\Support\Facades\Route;

// I-B inventory operations, included from app/Modules/Stock/routes.php. Mounted under /api/v1/stock-ops.
Route::prefix('stock-ops')->group(function () {
    // RFQ
    Route::get('rfqs', [O::class, 'rfqs']);
    Route::post('rfqs', [O::class, 'storeRfq']);
    Route::get('rfqs/{rfq}', [O::class, 'showRfq']);
    Route::post('rfqs/{rfq}/send', [O::class, 'sendRfq']);
    Route::post('rfqs/{rfq}/quotes', [O::class, 'quote']);
    Route::get('rfqs/{rfq}/grid', [O::class, 'grid']);
    Route::post('rfqs/{rfq}/award', [O::class, 'award']);
    Route::post('rfqs/{rfq}/convert', [O::class, 'convertRfq']);
    Route::post('rfqs/{rfq}/cancel', [O::class, 'cancelRfq']);
    Route::get('rfqs/{rfq}/supplier-sheet/{supplier}', [O::class, 'supplierSheet']);

    // Codes and scanning
    Route::get('scan/resolve', [O::class, 'resolve']);
    Route::get('scan/codes/item/{item}', [O::class, 'itemCodes']);
    Route::get('scan/codes/lot/{lot}', [O::class, 'lotCode']);
    Route::post('scan/barcodes', [O::class, 'registerBarcode']);
    Route::post('scan/receive', [O::class, 'scanReceive']);

    // Stocktake
    Route::get('stocktakes', [O::class, 'stocktakes']);
    Route::post('stocktakes', [O::class, 'startStocktake']);
    Route::get('stocktakes/{stocktake}/sheet', [O::class, 'sheet']);
    Route::post('stocktakes/{stocktake}/counts', [O::class, 'recordCounts']);
    Route::post('stocktakes/{stocktake}/scan', [O::class, 'scanCount']);
    Route::post('stocktakes/{stocktake}/close-count', [O::class, 'closeCount']);
    Route::get('stocktakes/{stocktake}/review', [O::class, 'reviewStocktake']);
    Route::post('stocktakes/{stocktake}/approve', [O::class, 'approveStocktake']);
    Route::post('stocktakes/{stocktake}/cancel', [O::class, 'cancelStocktake']);

    // Minibar
    Route::get('minibar/pars', [O::class, 'minibarPars']);
    Route::put('minibar/pars', [O::class, 'saveMinibarPar']);
    Route::get('minibar/tasks', [O::class, 'minibarTasks']);
    Route::post('minibar/tasks/{task}/complete', [O::class, 'completeMinibarTask']);
    Route::post('minibar/consumptions', [O::class, 'consumeMinibar']);

    // Menu engineering
    Route::get('menu-engineering', [O::class, 'menuEngineering']);

    // Uniforms and assets
    Route::get('issuables', [O::class, 'issuables']);
    Route::post('issuables', [O::class, 'storeIssuable']);
    Route::get('issues', [O::class, 'issues']);
    Route::post('issues', [O::class, 'issue']);
    Route::post('issues/{record}/return', [O::class, 'returnIssue']);
    Route::get('issues/employee/{employee}', [O::class, 'employeeIssues']);
});

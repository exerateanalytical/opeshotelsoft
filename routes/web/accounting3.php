<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A-B payables, tax and close screens. Each page carries the guard matching its API permission.
Route::middleware(['auth'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/payables-workflow', fn () => Inertia::render('Finance/PayablesWorkflow'))->middleware(\Spatie\Permission\Middleware\PermissionMiddleware::class.':payables.approve|payables.capture|accounting.tax|accounting.close')->name('payables_workflow');
    Route::get('/dunning', fn () => Inertia::render('Finance/Dunning'))->middleware('can:accounting.dunning')->name('dunning');
    Route::get('/bank-feed', fn () => Inertia::render('Finance/BankFeed'))->middleware('can:accounting.bank_feed')->name('bank_feed');
    Route::get('/tax', fn () => Inertia::render('Finance/TaxVat'))->middleware('can:accounting.tax')->name('tax');
    Route::get('/payroll-filings', fn () => Inertia::render('Finance/PayrollFilings'))->middleware('can:payroll.filings')->name('payroll_filings');
    Route::get('/statements-pack', fn () => Inertia::render('Finance/StatementsPack'))->middleware('can:accounting.statements')->name('statements_pack');
    Route::get('/cash-forecast', fn () => Inertia::render('Finance/CashForecast'))->middleware('can:accounting.forecast')->name('cash_forecast');
    Route::get('/month-close', fn () => Inertia::render('Finance/MonthClose'))->middleware('can:accounting.close')->name('month_close');
});

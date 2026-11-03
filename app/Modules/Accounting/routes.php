<?php

use App\Modules\Accounting\Http\AccountingController as A;
use App\Modules\Accounting\Http\FinanceExtrasController as X;
use App\Modules\Accounting\Http\ReceivablesController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounting')->group(function () {
    Route::get('accounts', [A::class, 'accounts']);
    Route::put('accounts', [A::class, 'saveAccount']);
    Route::get('journals', [A::class, 'journals']);
    Route::get('entries', [A::class, 'entries']);
    Route::post('entries', [A::class, 'postManual']);
    Route::get('entries/{entry}', [A::class, 'entry']);
    Route::post('entries/{entry}/reverse', [A::class, 'reverse']);
    Route::get('reports/trial-balance', [A::class, 'trialBalance']);
    Route::get('reports/general-ledger', [A::class, 'generalLedger']);
    Route::get('reports/income-statement', [A::class, 'incomeStatement']);
    Route::get('reports/balance-sheet', [A::class, 'balanceSheet']);
    Route::get('reports/vat', [A::class, 'vatReport']);
    Route::get('reports/departmental', [A::class, 'departmental']);
    Route::get('reports/receivables-ageing', [A::class, 'receivablesAgeing']);
    Route::get('reports/consolidated', [A::class, 'consolidated']);
    Route::get('periods', [A::class, 'periods']);
    Route::post('periods/close', [A::class, 'closePeriod']);
    Route::post('periods/reopen', [A::class, 'reopenPeriod']);

    // W10: reconciliation
    Route::get('bank-accounts', [X::class, 'bankAccounts']);
    Route::post('bank-accounts', [X::class, 'saveBankAccount']);
    Route::post('bank-accounts/{bank}/statements', [X::class, 'importStatement']);
    Route::get('bank-accounts/{bank}/imports', [X::class, 'imports']);
    Route::get('bank-accounts/{bank}/statement-lines', [X::class, 'statementLines']);
    Route::get('bank-accounts/{bank}/ledger-lines', [X::class, 'openLedgerLines']);
    Route::post('bank-accounts/{bank}/auto-match', [X::class, 'autoMatch']);
    Route::get('bank-accounts/{bank}/reconciliation', [X::class, 'reconciliationReport']);
    Route::post('statement-lines/{line}/match', [X::class, 'match'])->whereNumber('line');
    Route::post('statement-lines/{line}/unmatch', [X::class, 'unmatch'])->whereNumber('line');
    Route::post('statement-lines/{line}/post-difference', [X::class, 'postDifference'])->whereNumber('line');
    // W10: fixed assets
    Route::get('asset-classes', [X::class, 'assetClasses']);
    Route::post('asset-classes', [X::class, 'saveAssetClass']);
    Route::get('fixed-assets', [X::class, 'assets']);
    Route::post('fixed-assets', [X::class, 'acquireAsset']);
    Route::post('fixed-assets/{asset}/dispose', [X::class, 'disposeAsset']);
    Route::get('depreciation-runs', [X::class, 'depreciationRuns']);
    Route::post('depreciation-runs', [X::class, 'runDepreciation']);
    // W10: reports, FX, year-end
    Route::get('reports/cash-flow', [X::class, 'cashFlow']);
    Route::get('reports/dsf', [X::class, 'dsf']);
    Route::get('fx-revaluations', [X::class, 'fxRuns']);
    Route::get('fx-revaluations/preview', [X::class, 'fxPreview']);
    Route::post('fx-revaluations', [X::class, 'fxRun']);
    Route::get('year-end', [X::class, 'yearStatus']);
    Route::post('year-end/close', [X::class, 'closeYear']);
});

Route::get('receivables/companies/{company}/statement', [ReceivablesController::class, 'statement']);
Route::post('receivables/invoices/{invoice}/payments', [ReceivablesController::class, 'pay']);

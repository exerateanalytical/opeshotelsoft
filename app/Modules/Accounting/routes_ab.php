<?php

use App\Modules\Accounting\Http\Ab\CloseController as K;
use App\Modules\Accounting\Http\Ab\PayablesController as P;
use App\Modules\Accounting\Http\Ab\TaxController as T;
use Illuminate\Support\Facades\Route;

// A-B payables, tax, close. Mounted from Accounting/routes.php under /api/v1/accounting3.
Route::prefix('accounting3')->group(function () {
    // approvals and capture
    Route::get('payables/approval-chains', [P::class, 'chains']);
    Route::post('payables/approval-chains', [P::class, 'saveChain']);
    Route::put('payables/approval-chains/{id}', [P::class, 'saveChain'])->whereNumber('id');
    Route::post('payables/invoices/{invoice}/submit', [P::class, 'submit'])->whereNumber('invoice');
    Route::get('payables/invoices/{invoice}/approvals', [P::class, 'invoiceApprovals'])->whereNumber('invoice');
    Route::get('payables/approvals/inbox', [P::class, 'inbox']);
    Route::post('payables/approvals/{id}/decide', [P::class, 'decide'])->whereNumber('id');
    Route::get('payables/delegations', [P::class, 'delegations']);
    Route::post('payables/delegations', [P::class, 'delegate']);
    Route::post('payables/delegations/{id}/revoke', [P::class, 'revokeDelegation'])->whereNumber('id');
    Route::get('payables/captures', [P::class, 'captures']);
    Route::post('payables/captures', [P::class, 'upload']);
    Route::get('payables/captures/{id}', [P::class, 'capture'])->whereNumber('id');
    Route::get('payables/captures/{id}/file', [P::class, 'captureFile'])->whereNumber('id');
    Route::post('payables/captures/{id}/convert', [P::class, 'convert'])->whereNumber('id');

    // dunning
    Route::get('dunning/settings', [P::class, 'dunningSettings']);
    Route::put('dunning/settings', [P::class, 'saveDunningSettings']);
    Route::put('dunning/levels/{level}', [P::class, 'saveDunningLevel'])->whereNumber('level');
    Route::get('dunning/candidates', [P::class, 'dunningCandidates']);
    Route::post('dunning/send', [P::class, 'dunningSend']);
    Route::get('dunning/notices', [P::class, 'dunningNotices']);
    Route::get('dunning/holds', [P::class, 'dunningHolds']);
    Route::post('dunning/holds', [P::class, 'dunningHold']);
    Route::post('dunning/holds/{id}/release', [P::class, 'dunningRelease'])->whereNumber('id');

    // bank feed and rules
    Route::post('bank-accounts/{bank}/feed', [P::class, 'feed'])->whereNumber('bank');
    Route::post('bank-accounts/{bank}/apply-rules', [P::class, 'applyRules'])->whereNumber('bank');
    Route::get('bank-accounts/{bank}/rule-suggestions', [P::class, 'suggestions'])->whereNumber('bank');
    Route::post('statement-lines/{line}/accept-rule', [P::class, 'accept'])->whereNumber('line');
    Route::get('bank-rules', [P::class, 'rules']);
    Route::post('bank-rules', [P::class, 'saveRule']);
    Route::put('bank-rules/{id}', [P::class, 'saveRule'])->whereNumber('id');

    // tax
    Route::get('tax/profile', [T::class, 'profile']);
    Route::put('tax/profile', [T::class, 'saveProfile']);
    Route::get('tax/vat-returns', [T::class, 'vatReturns']);
    Route::get('tax/vat-returns/preview', [T::class, 'vatPreview']);
    Route::post('tax/vat-returns', [T::class, 'vatPrepare']);
    Route::get('tax/vat-returns/{id}', [T::class, 'vatShow'])->whereNumber('id');
    Route::post('tax/vat-returns/{id}/file', [T::class, 'vatFile'])->whereNumber('id');
    Route::post('tax/vat-returns/{id}/amend', [T::class, 'vatAmend'])->whereNumber('id');
    Route::get('tax/vat-returns/{id}/verify', [T::class, 'vatVerify'])->whereNumber('id');
    Route::get('tax/vat-returns/{id}/export', [T::class, 'vatExport'])->whereNumber('id');
    Route::get('tax/withholding-rates', [T::class, 'whtRates']);
    Route::post('tax/withholding-rates', [T::class, 'saveWhtRate']);
    Route::put('tax/suppliers/{supplier}/profile', [T::class, 'supplierProfile'])->whereNumber('supplier');
    Route::get('tax/withholding-certificates', [T::class, 'certificates']);
    Route::post('tax/withholding-certificates/remit', [T::class, 'remit']);
    Route::get('tax/withholding-certificates/{id}', [T::class, 'certificate'])->whereNumber('id');
    Route::get('tax/stamp-duty', [T::class, 'stampRules']);
    Route::post('tax/stamp-duty/rules', [T::class, 'saveStampRule']);
    Route::post('tax/stamp-duty', [T::class, 'stampRecord']);
    Route::get('tax/payroll-filings', [T::class, 'payrollFilings']);
    Route::get('tax/payroll-filings/{kind}', [T::class, 'payrollPreview']);
    Route::get('tax/payroll-filings/{kind}/export', [T::class, 'payrollExport']);
    Route::post('tax/payroll-filings/{id}/filed', [T::class, 'payrollFiled'])->whereNumber('id');

    // close
    Route::get('close/statements', [K::class, 'statements']);
    Route::post('close/statement-packs', [K::class, 'savePack']);
    Route::get('close/statement-packs', [K::class, 'packs']);
    Route::get('close/statement-packs/{id}', [K::class, 'pack'])->whereNumber('id');
    Route::post('close/statement-packs/{id}/finalize', [K::class, 'finalizePack'])->whereNumber('id');
    Route::get('close/forecast', [K::class, 'forecast']);
    Route::get('close/templates', [K::class, 'templates']);
    Route::post('close/templates', [K::class, 'saveTemplate']);
    Route::put('close/templates/{id}', [K::class, 'saveTemplate'])->whereNumber('id');
    Route::get('close/runs', [K::class, 'runs']);
    Route::post('close/runs', [K::class, 'start']);
    Route::get('close/runs/{year}/{month}', [K::class, 'run'])->whereNumber(['year', 'month']);
    Route::post('close/tasks/{id}/assign', [K::class, 'assign'])->whereNumber('id');
    Route::post('close/tasks/{id}/check', [K::class, 'check'])->whereNumber('id');
    Route::post('close/tasks/{id}/sign', [K::class, 'sign'])->whereNumber('id');
});

<?php

use App\Modules\Documents\Http\DocumentController as D;
use App\Modules\Documents\Http\ExportController as E;
use App\Modules\Documents\Http\ScheduledReportController as S;
use Illuminate\Support\Facades\Route;

// PDFs: permission and tenant checks are those of the underlying resource endpoint.
Route::prefix('documents')->group(function () {
    Route::get('invoices/{invoice}', [D::class, 'invoice'])->whereNumber('invoice');
    Route::get('folios/{folio}', [D::class, 'folio'])->whereNumber('folio');
    Route::get('registration-cards/{reservation}', [D::class, 'registrationCard'])->whereNumber('reservation');
    Route::get('payslips/{payslip}', [D::class, 'payslip'])->whereNumber('payslip');
    Route::get('purchase-orders/{order}', [D::class, 'purchaseOrder'])->whereNumber('order');
});

Route::get('exports', [E::class, 'index']);
Route::get('exports/{report}', [E::class, 'export'])->where('report', '[a-z-]+');

Route::get('scheduled-reports', [S::class, 'index']);
Route::post('scheduled-reports', [S::class, 'store']);
Route::put('scheduled-reports/{scheduledReport}', [S::class, 'update'])->whereNumber('scheduledReport');
Route::delete('scheduled-reports/{scheduledReport}', [S::class, 'destroy'])->whereNumber('scheduledReport');
Route::post('scheduled-reports/{scheduledReport}/run', [S::class, 'run'])->whereNumber('scheduledReport');

// Q1: letterhead / branding and document templates.
use App\Modules\Documents\Http\BrandingController as B;
use App\Modules\Documents\Http\TemplateController as T;

Route::prefix('documents')->group(function () {
    Route::get('branding', [B::class, 'show']);
    Route::put('branding', [B::class, 'update']);
    Route::post('branding/logo', [B::class, 'uploadLogo']);
    Route::post('branding/logo/remove', [B::class, 'removeLogo']);

    Route::get('templates', [T::class, 'index']);
    Route::post('templates/preview', [T::class, 'preview']);
    Route::get('templates/{kind}/print', [T::class, 'print'])->whereIn('kind', ['quote', 'confirmation', 'letter']);
    Route::put('templates/{kind}/{locale}', [T::class, 'update']);
    Route::post('templates/{kind}/{locale}/reset', [T::class, 'reset']);
});

// D-A: watermark, letterhead designs and the issued-documents register.
use App\Modules\Documents\Http\IssuedController as I;

Route::prefix('documents')->group(function () {
    Route::get('branding/designs', [B::class, 'designs']);
    Route::get('branding/preview', [B::class, 'preview']);
    Route::get('branding/sample', [B::class, 'sample']);
    Route::get('watermark', [B::class, 'watermark']);
    Route::put('watermark', [B::class, 'saveWatermark']);
    Route::post('watermark/image', [B::class, 'uploadWatermark']);
    Route::post('watermark/image/remove', [B::class, 'removeWatermark']);

    Route::get('issued', [I::class, 'index']);
    Route::get('issued/{issued}', [I::class, 'show'])->whereNumber('issued');
});

// D-B: money documents (receipts, credit notes, statements, reports, journal vouchers...). See docs/DOCUMENTS-MONEY.md.
use App\Modules\Documents\Money\MoneyController as MD;

Route::prefix('documents/money')->group(function () {
    Route::post('credit-notes', [MD::class, 'storeCreditNote']);
    Route::get('{kind}/{id?}', [MD::class, 'show'])->whereIn('kind', MD::KINDS)->whereNumber('id');
});

// D-C: operations and HR documents (group contract, police register, stock notes, HR letters, ID card...). See docs/DOCUMENTS-OPS.md.
use App\Modules\Documents\Operations\OpsController as OD;

Route::prefix('documents/ops')->group(function () {
    Route::post('{kind}/{id}/regenerate', [OD::class, 'regenerate'])->whereIn('kind', OD::KINDS)->whereNumber('id');
    Route::get('{kind}/{id?}', [OD::class, 'show'])->whereIn('kind', OD::KINDS)->whereNumber('id');
});

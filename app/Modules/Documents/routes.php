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

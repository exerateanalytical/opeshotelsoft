<?php

use App\Modules\Accounting\Http\Core\CoreController as K;
use Illuminate\Support\Facades\Route;

// A-A core ledger. Mounted from Accounting/routes.php, under /api/v1/accounting/core.
Route::prefix('accounting/core')->group(function () {
    Route::get('budgets', [K::class, 'budgets']);
    Route::post('budgets', [K::class, 'saveBudget']);
    Route::get('budgets/group', [K::class, 'budgetGroup']);
    Route::get('budgets/{id}', [K::class, 'budget'])->whereNumber('id');
    Route::post('budgets/{id}/approve', [K::class, 'approveBudget'])->whereNumber('id');
    Route::get('budgets/{id}/variance', [K::class, 'budgetVariance'])->whereNumber('id');

    Route::get('dimensions', [K::class, 'dimensions']);
    Route::post('dimensions', [K::class, 'saveDimension']);
    Route::post('dimension-defaults', [K::class, 'saveDimensionDefault']);
    Route::get('reports/dimension', [K::class, 'dimensionReport']);

    Route::get('schedules', [K::class, 'schedules']);
    Route::post('schedules', [K::class, 'createSchedule']);
    Route::get('schedules/{id}/items', [K::class, 'scheduleItems'])->whereNumber('id');
    Route::post('schedules/{id}/cancel', [K::class, 'cancelSchedule'])->whereNumber('id');
    Route::post('schedules/run', [K::class, 'runSchedules']);
    Route::get('recurring', [K::class, 'recurring']);
    Route::post('recurring', [K::class, 'saveRecurring']);

    Route::get('period-locks', [K::class, 'lockRequests']);
    Route::post('period-locks', [K::class, 'requestLock']);
    Route::post('period-locks/{id}/decide', [K::class, 'decideLock'])->whereNumber('id');

    Route::post('entries', [K::class, 'postManual']);
    Route::post('entries/{entry}/attachments', [K::class, 'attach'])->whereNumber('entry');
    Route::get('entries/{entry}/attachments', [K::class, 'attachments'])->whereNumber('entry');
    Route::get('attachments/{attachment}', [K::class, 'downloadAttachment'])->whereNumber('attachment');
    Route::post('entries/{entry}/reverse', [K::class, 'reverse'])->whereNumber('entry');
    Route::get('entries/{entry}/audit', [K::class, 'entryAudit'])->whereNumber('entry');
    Route::get('audit-log', [K::class, 'auditLog']);

    Route::get('intercompany', [K::class, 'intercompany']);
    Route::post('intercompany', [K::class, 'postIntercompany']);
    Route::post('intercompany/{id}/reverse', [K::class, 'reverseIntercompany'])->whereNumber('id');
    Route::get('consolidation', [K::class, 'consolidation']);

    Route::get('fx/exposure', [K::class, 'fxExposure']);
    Route::post('fx/month-end', [K::class, 'fxMonthEnd']);
});

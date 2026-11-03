<?php

use App\Modules\People\Http\PeopleController as P;
use App\Modules\People\Http\PerformanceController as R;
use App\Modules\People\Http\TaskController as T;
use Illuminate\Support\Facades\Route;

Route::prefix('people')->group(function () {
    // Performance
    Route::get('cycles', [R::class, 'cycles']);
    Route::post('cycles', [R::class, 'storeCycle']);
    Route::get('cycles/{cycle}', [R::class, 'showCycle']);
    Route::post('cycles/{cycle}/open', [R::class, 'open']);
    Route::post('cycles/{cycle}/calibration', [R::class, 'startCalibration']);
    Route::post('cycles/{cycle}/close', [R::class, 'close']);
    Route::get('cycles/{cycle}/calibration-summary', [R::class, 'summary']);
    Route::get('reviews/mine', [R::class, 'mine']);
    Route::get('reviews/{review}', [R::class, 'showReview']);
    Route::put('reviews/{review}/objectives', [R::class, 'objectives']);
    Route::post('reviews/{review}/self', [R::class, 'self']);
    Route::post('reviews/{review}/manager', [R::class, 'manager']);
    Route::post('reviews/{review}/calibrate', [R::class, 'calibrate']);

    // Guest feedback
    Route::get('feedback', [P::class, 'feedback']);
    Route::post('feedback', [P::class, 'storeFeedback']);
    Route::get('employees/{employee}/feedback-summary', [P::class, 'feedbackSummary'])->whereNumber('employee');

    // Tasks
    Route::get('tasks', [T::class, 'index']);
    Route::get('tasks/mine', [T::class, 'mine']);
    Route::get('tasks/overdue', [T::class, 'overdue']);
    Route::post('tasks', [T::class, 'store']);
    Route::get('tasks/{task}', [T::class, 'show']);
    Route::post('tasks/{task}/assign', [T::class, 'assign']);
    Route::post('tasks/{task}/start', [T::class, 'start']);
    Route::post('tasks/{task}/checklist/{index}', [T::class, 'check'])->whereNumber('index');
    Route::post('tasks/{task}/photos', [T::class, 'photo']);
    Route::get('tasks/{task}/photos/{photo}', [T::class, 'downloadPhoto']);
    Route::post('tasks/{task}/complete', [T::class, 'complete']);
    Route::post('tasks/{task}/cancel', [T::class, 'cancel']);

    // Discipline
    Route::get('sanctions', [P::class, 'sanctions']);
    Route::post('sanctions', [P::class, 'storeSanction']);
    Route::get('sanctions/{sanction}', [P::class, 'showSanction']);
    Route::post('sanctions/{sanction}/acknowledge', [P::class, 'acknowledge']);
    Route::post('sanctions/{sanction}/withdraw', [P::class, 'withdraw']);
    Route::post('sanctions/{sanction}/documents', [P::class, 'attach']);
    Route::get('sanctions/{sanction}/documents/{document}', [P::class, 'downloadDocument']);

    // Exit
    Route::get('exits', [P::class, 'exits']);
    Route::post('exits', [P::class, 'storeExit']);
    Route::get('exits/{exit}', [P::class, 'showExit']);
    Route::patch('exits/{exit}', [P::class, 'updateExit']);
    Route::post('exits/{exit}/approve', [P::class, 'approveExit']);
    Route::get('exits/{exit}/work-certificate', [P::class, 'certificate']);
});

<?php

use App\Modules\Housekeeping\Http\HousekeepingController as H;
use Illuminate\Support\Facades\Route;

Route::prefix('housekeeping')->group(function () {
    Route::get('board', [H::class, 'board']);
    Route::get('tasks', [H::class, 'tasks']);
    Route::post('tasks/generate', [H::class, 'generate']);
    Route::post('tasks/{task}/assign', [H::class, 'assign']);
    Route::post('tasks/{task}/start', [H::class, 'start']);
    Route::post('tasks/{task}/finish', [H::class, 'finish']);
    Route::post('tasks/{task}/inspect', [H::class, 'inspect']);
    Route::post('rooms/{room}/status', [H::class, 'setRoomStatus']);
    Route::get('lost-found', [H::class, 'lostFound']);
    Route::post('lost-found', [H::class, 'storeLostFound']);
    Route::post('lost-found/{item}/return', [H::class, 'returnLostFound']);
    Route::post('lost-found/{item}/dispose', [H::class, 'disposeLostFound']);
});

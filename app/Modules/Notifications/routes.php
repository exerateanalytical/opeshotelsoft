<?php

use App\Modules\Notifications\Http\NotificationController as N;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->group(function () {
    Route::get('templates', [N::class, 'templates']);
    Route::put('templates/{id}', [N::class, 'updateTemplate'])->whereNumber('id');
    Route::post('templates/preview', [N::class, 'preview']);
    Route::get('outbox', [N::class, 'outbox']);
    Route::post('outbox/{id}/resend', [N::class, 'resend'])->whereNumber('id');
    Route::get('preferences/{guest}', [N::class, 'preferences'])->whereNumber('guest');
    Route::put('preferences/{guest}', [N::class, 'updatePreferences'])->whereNumber('guest');
});

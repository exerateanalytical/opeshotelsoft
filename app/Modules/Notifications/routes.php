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

// Q6 WhatsApp staff inbox, quick replies, template registry.
use App\Modules\Notifications\Http\MessagingController as M;

Route::prefix('messaging')->group(function () {
    // GAP-G bulk send from the reservations list (messaging.reply or messaging.manage, checked in the controller).
    Route::post('bulk', [\App\Modules\Notifications\Http\BulkMessageController::class, 'send']);
    Route::middleware('can:messaging.view')->group(function () {
        Route::get('conversations', [M::class, 'conversations']);
        Route::get('conversations/{id}', [M::class, 'show'])->whereNumber('id');
        Route::get('quick-replies', [M::class, 'quickReplies']);
        Route::get('templates', [M::class, 'templates']);
        Route::get('staff', [M::class, 'staff']);
    });
    Route::middleware('can:messaging.reply')->group(function () {
        Route::post('conversations/{id}/reply', [M::class, 'reply'])->whereNumber('id');
        Route::post('conversations/{id}/media', [M::class, 'media'])->whereNumber('id');
        Route::post('conversations/{id}/template', [M::class, 'template'])->whereNumber('id');
        Route::post('conversations/{id}/assign', [M::class, 'assign'])->whereNumber('id');
        Route::post('conversations/{id}/status', [M::class, 'status'])->whereNumber('id');
    });
    Route::middleware('can:messaging.manage')->group(function () {
        Route::post('quick-replies', [M::class, 'storeQuickReply']);
        Route::put('quick-replies/{id}', [M::class, 'updateQuickReply'])->whereNumber('id');
        Route::delete('quick-replies/{id}', [M::class, 'destroyQuickReply'])->whereNumber('id');
        Route::put('templates/{id}', [M::class, 'updateTemplate'])->whereNumber('id');
        Route::get('accounts', [M::class, 'accounts']);
        Route::post('accounts', [M::class, 'storeAccount']);
    });
});

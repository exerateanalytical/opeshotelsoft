<?php

use App\Modules\GuestPortal\Http\GuestOrderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

// Q6 WhatsApp staff inbox.
Route::middleware(['auth'])->group(function () {
    Route::get('/messaging', fn () => Inertia::render('Messaging/Inbox'))
        ->middleware(PermissionMiddleware::class.':messaging.view|messaging.manage')->name('messaging.inbox');
    Route::get('/messaging/templates', fn () => Inertia::render('Messaging/Templates'))
        ->middleware(PermissionMiddleware::class.':messaging.manage')->name('messaging.templates');
});

// Signed guest ordering page (no login).
Route::get('/g/order/{reservation}', [GuestOrderController::class, 'page'])
    ->middleware(['signed', 'throttle:public-read'])->whereNumber('reservation')->name('guest.order');

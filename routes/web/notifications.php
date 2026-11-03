<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

$notif = PermissionMiddleware::class.':notifications.view|notifications.manage';

// W04 notifications.
Route::middleware(['auth'])->group(function () use ($notif) {
    Route::get('/notifications/templates', fn () => Inertia::render('Notifications/Templates'))->middleware($notif)->name('notifications.templates');
    Route::get('/notifications/outbox', fn () => Inertia::render('Notifications/Outbox'))->middleware($notif)->name('notifications.outbox');
});

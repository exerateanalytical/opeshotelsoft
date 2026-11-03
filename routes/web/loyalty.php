<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

// P1 loyalty cards, promotions, statements, POS counter.
Route::middleware(['auth'])->prefix('loyalty')->name('loyalty.')->group(function () {
    $view = PermissionMiddleware::class.':loyalty.view|loyalty.manage|pos.manage|frontdesk.manage';
    Route::get('/cards/{card}', fn (int $card) => Inertia::render('Loyalty/Card', ['cardId' => $card]))->whereNumber('card')->middleware($view)->name('card');
    Route::get('/members/{member}/statement', fn (int $member) => Inertia::render('Loyalty/Statement', ['memberId' => $member]))->whereNumber('member')->middleware($view)->name('statement');
    Route::get('/promotions', fn () => Inertia::render('Loyalty/Promotions'))->middleware($view)->name('promotions');
    Route::get('/counter', fn () => Inertia::render('Loyalty/Counter'))->middleware(PermissionMiddleware::class.':pos.manage|loyalty.manage')->name('counter');
});

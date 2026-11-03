<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;

// Q3 People: performance, feedback, tasks, discipline, exits.
Route::middleware(['auth'])->prefix('people')->name('people.')->group(function () {
    $view = PermissionMiddleware::class.':people.view|people.manage';
    Route::get('/', fn () => Inertia::render('People/Index'))->middleware($view)->name('index');
    Route::get('/reviews', fn () => Inertia::render('People/Reviews'))->middleware($view)->name('reviews');
    Route::get('/feedback', fn () => Inertia::render('People/Feedback'))->middleware($view)->name('feedback');
    Route::get('/tasks', fn () => Inertia::render('People/Tasks'))->middleware(PermissionMiddleware::class.':people.view|people.tasks|people.manage')->name('tasks');
    Route::get('/discipline', fn () => Inertia::render('People/Discipline'))->middleware(PermissionMiddleware::class.':people.discipline')->name('discipline');
    Route::get('/exits', fn () => Inertia::render('People/Exits'))->middleware(PermissionMiddleware::class.':people.exit')->name('exits');
});

<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Staff task home: mobile-first "my work" page for every signed-in staff member (data is filtered per permission by the API).
Route::middleware(['auth'])->get('/tasks', fn () => Inertia::render('Tasks/Index'))->name('tasks.home');

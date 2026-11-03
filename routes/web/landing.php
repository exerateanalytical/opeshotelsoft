<?php

use App\Http\Controllers\DemoRequestController;
use Illuminate\Support\Facades\Route;

// Public landing page: demo request form (the page itself is served by the "/" route in web.php).
Route::post('/demo-request', [DemoRequestController::class, 'store'])->middleware('throttle:5,10')->name('demo-request');

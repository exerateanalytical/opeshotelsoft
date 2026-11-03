<?php

use App\Modules\Channels\Http\InboundController;
use Illuminate\Support\Facades\Route;

Route::post('channels/inbound/{uuid}', [InboundController::class, 'receive'])->whereUuid('uuid')->middleware('throttle:public-read');
Route::get('public/meta/{uuid}/availability', [InboundController::class, 'meta'])->whereUuid('uuid')->middleware('throttle:public-read');

<?php

use App\Modules\Platform\Http\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('webhooks', [WebhookController::class, 'index']);
Route::post('webhooks', [WebhookController::class, 'store']);
Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy']);
Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
Route::post('webhooks/{webhook}/deliveries/{delivery}/replay', [WebhookController::class, 'replay']);
Route::get('events', [WebhookController::class, 'events']);

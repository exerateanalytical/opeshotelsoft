<?php

use App\Modules\Notifications\Http\WhatsAppWebhookController as W;
use Illuminate\Support\Facades\Route;

// WhatsApp Cloud API webhook (Meta). No login: POST is authenticated by X-Hub-Signature-256.
Route::get('webhooks/whatsapp', [W::class, 'verify'])->middleware('throttle:60,1');
Route::post('webhooks/whatsapp', [W::class, 'receive'])->middleware('throttle:600,1');

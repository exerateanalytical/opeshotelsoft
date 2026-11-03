<?php

namespace App\Modules\Notifications\Http;

use App\Modules\Notifications\Application\WhatsApp\WhatsAppClient;
use App\Modules\Notifications\Application\WhatsApp\WhatsAppMessenger;
use Illuminate\Http\Request;

/** Meta WhatsApp Cloud API webhook: GET subscription handshake, POST signed events. */
class WhatsAppWebhookController
{
    public function verify(Request $request)
    {
        $token = WhatsAppClient::cfg('verify_token', 'NOTIFY_WHATSAPP_VERIFY_TOKEN');
        abort_unless($token && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppMessenger $wa)
    {
        abort_unless(WhatsAppClient::validSignature($request->getContent(), $request->header('X-Hub-Signature-256')), 401, 'Invalid signature');

        return response()->json(['status' => 'ok'] + $wa->receive($request->json()->all()));
    }
}

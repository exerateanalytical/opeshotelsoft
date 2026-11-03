<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class OrangeSmsDriver implements ChannelDriver
{
    public function __construct(private array $c) {}

    public function name(): string
    {
        return 'sms-orange';
    }

    public function send(OutboxMessage $m): string
    {
        $base = rtrim($this->c['url'] ?? 'https://api.orange.com', '/');
        $token = Http::asForm()->withBasicAuth($this->c['client_id'], $this->c['client_secret'])->timeout(15)
            ->post("$base/oauth/v3/token", ['grant_type' => 'client_credentials'])->throw()->json('access_token');
        $sender = 'tel:+'.ltrim($this->c['sender'], '+');
        $r = Http::withToken($token)->acceptJson()->timeout(15)
            ->post("$base/smsmessaging/v1/outbound/".rawurlencode($sender).'/requests', ['outboundSMSMessageRequest' => [
                'address' => 'tel:+'.ltrim($m->recipient, '+'), 'senderAddress' => $sender,
                'outboundSMSTextMessage' => ['message' => $m->body],
            ]])->throw();

        return (string) ($r->json('outboundSMSMessageRequest.resourceURL') ?? 'orange-'.Str::uuid());
    }
}

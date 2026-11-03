<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class WhatsAppCloudDriver implements ChannelDriver
{
    public function __construct(private array $c) {}

    public function name(): string
    {
        return 'whatsapp-meta';
    }

    public function send(OutboxMessage $m): string
    {
        $base = rtrim($this->c['url'] ?? 'https://graph.facebook.com/v21.0', '/');
        $r = Http::withToken($this->c['token'])->acceptJson()->timeout(15)
            ->post("$base/{$this->c['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp', 'to' => ltrim($m->recipient, '+'), 'type' => 'text', 'text' => ['body' => $m->body],
            ])->throw();

        return (string) ($r->json('messages.0.id') ?? 'wa-'.Str::uuid());
    }
}

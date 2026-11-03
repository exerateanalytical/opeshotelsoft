<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class HttpSmsDriver implements ChannelDriver
{
    public function __construct(private array $c) {}

    public function name(): string
    {
        return 'sms-http';
    }

    public function send(OutboxMessage $m): string
    {
        $r = Http::withToken($this->c['token'])->acceptJson()->timeout(15)
            ->post($this->c['url'], ['to' => $m->recipient, 'from' => $this->c['sender'] ?? null, 'message' => $m->body])->throw();

        return (string) ($r->json('id') ?? $r->json('message_id') ?? 'http-'.Str::uuid());
    }
}

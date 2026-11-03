<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class TwilioSmsDriver implements ChannelDriver
{
    public function __construct(private array $c) {}

    public function name(): string
    {
        return 'sms-twilio';
    }

    public function send(OutboxMessage $m): string
    {
        $base = rtrim($this->c['url'] ?? 'https://api.twilio.com', '/');
        $r = Http::asForm()->withBasicAuth($this->c['sid'], $this->c['token'])->timeout(15)
            ->post("$base/2010-04-01/Accounts/{$this->c['sid']}/Messages.json", ['To' => $m->recipient, 'From' => $this->c['sender'], 'Body' => $m->body])->throw();

        return (string) ($r->json('sid') ?? 'twilio-'.Str::uuid());
    }
}

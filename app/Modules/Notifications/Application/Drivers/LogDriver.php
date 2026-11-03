<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class LogDriver implements ChannelDriver
{
    public function __construct(private string $channel) {}

    public function name(): string
    {
        return 'log';
    }

    public function send(OutboxMessage $m): string
    {
        Log::info("[notifications:$this->channel] to {$m->recipient}", ['subject' => $m->subject, 'body' => $m->body]);

        return 'log-'.Str::uuid();
    }
}

<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class MailDriver implements ChannelDriver
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(OutboxMessage $m): string
    {
        $sent = Mail::raw($m->body, fn ($msg) => $msg->to($m->recipient)->subject($m->subject ?? ''));

        return $sent?->getMessageId() ?? 'mail-'.Str::uuid();
    }
}

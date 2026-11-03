<?php

namespace App\Modules\Notifications\Application\Drivers;


use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

interface ChannelDriver
{
    public function name(): string;

    /** Send the message; return the provider message id. Throw on failure. */
    public function send(OutboxMessage $m): string;
}

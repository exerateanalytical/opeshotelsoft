<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Application\Drivers\ChannelDriver;
use App\Modules\Notifications\Application\Drivers\HttpSmsDriver;
use App\Modules\Notifications\Application\Drivers\LogDriver;
use App\Modules\Notifications\Application\Drivers\MailDriver;
use App\Modules\Notifications\Application\Drivers\OrangeSmsDriver;
use App\Modules\Notifications\Application\Drivers\TwilioSmsDriver;
use App\Modules\Notifications\Application\Drivers\WhatsAppCloudDriver;

/**
 * Resolves the driver for a channel from configuration
 * (config('notifications.*'), falling back to NOTIFY_* env vars).
 * Any channel without credentials uses the LogDriver.
 */
class ChannelManager
{
    /** @var array<string, ChannelDriver> */
    private array $overrides = [];

    public function extend(string $channel, ChannelDriver $driver): void
    {
        $this->overrides[$channel] = $driver;
    }

    public function driver(string $channel): ChannelDriver
    {
        if (isset($this->overrides[$channel])) {
            return $this->overrides[$channel];
        }

        return match ($channel) {
            'email' => $this->cfg('email.driver', 'NOTIFY_EMAIL_DRIVER', 'mail') === 'log' ? new LogDriver('email') : new MailDriver,
            'sms' => $this->sms(),
            'whatsapp' => $this->whatsapp(),
            default => new LogDriver($channel),
        };
    }

    private function sms(): ChannelDriver
    {
        $c = [
            'url' => $this->cfg('sms.url', 'NOTIFY_SMS_URL'), 'token' => $this->cfg('sms.token', 'NOTIFY_SMS_TOKEN'),
            'sender' => $this->cfg('sms.sender', 'NOTIFY_SMS_SENDER'),
            'client_id' => $this->cfg('sms.client_id', 'NOTIFY_SMS_CLIENT_ID'), 'client_secret' => $this->cfg('sms.client_secret', 'NOTIFY_SMS_CLIENT_SECRET'),
            'sid' => $this->cfg('sms.sid', 'NOTIFY_SMS_SID'),
        ];

        return match ($this->cfg('sms.driver', 'NOTIFY_SMS_DRIVER', 'log')) {
            'http' => $c['url'] && $c['token'] ? new HttpSmsDriver($c) : new LogDriver('sms'),
            'orange' => $c['client_id'] && $c['client_secret'] && $c['sender'] ? new OrangeSmsDriver(array_filter($c)) : new LogDriver('sms'),
            'twilio' => $c['sid'] && $c['token'] && $c['sender'] ? new TwilioSmsDriver(array_filter($c)) : new LogDriver('sms'),
            default => new LogDriver('sms'),
        };
    }

    private function whatsapp(): ChannelDriver
    {
        $c = array_filter([
            'url' => $this->cfg('whatsapp.url', 'NOTIFY_WHATSAPP_URL'),
            'token' => $this->cfg('whatsapp.token', 'NOTIFY_WHATSAPP_TOKEN'),
            'phone_number_id' => $this->cfg('whatsapp.phone_number_id', 'NOTIFY_WHATSAPP_PHONE_ID'),
        ]);

        return isset($c['token'], $c['phone_number_id']) ? new WhatsAppCloudDriver($c) : new LogDriver('whatsapp');
    }

    private function cfg(string $key, string $env, ?string $default = null): ?string
    {
        return config("notifications.$key") ?? env($env, $default);
    }
}

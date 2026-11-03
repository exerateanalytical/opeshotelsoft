<?php

namespace App\Modules\Notifications\Application\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp Cloud API (Meta Graph) client: template, session text and media messages.
 * Without a token and phone number id it runs in dry-run mode: nothing leaves the
 * server, the payload is logged and a `dry-` message id is returned.
 *
 * Config: notifications.whatsapp.{url,token,phone_number_id,app_secret,verify_token}
 * or NOTIFY_WHATSAPP_{URL,TOKEN,PHONE_ID,APP_SECRET,VERIFY_TOKEN}.
 */
class WhatsAppClient
{
    public static function cfg(string $key, string $env): ?string
    {
        return config("notifications.whatsapp.$key") ?? env($env);
    }

    public function dryRun(): bool
    {
        return ! self::cfg('token', 'NOTIFY_WHATSAPP_TOKEN') || ! self::cfg('phone_number_id', 'NOTIFY_WHATSAPP_PHONE_ID');
    }

    /** @param  list<string>  $params  body parameters for {{1}}..{{n}} */
    public function sendTemplate(string $to, string $name, string $locale, array $params = []): string
    {
        return $this->post($to, [
            'type' => 'template',
            'template' => [
                'name' => $name, 'language' => ['code' => $locale === 'en' ? 'en' : 'fr'],
                'components' => $params ? [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params))]] : [],
            ],
        ]);
    }

    public function sendText(string $to, string $body): string
    {
        return $this->post($to, ['type' => 'text', 'text' => ['body' => $body, 'preview_url' => true]]);
    }

    /** @param  string  $type  image, document, audio or video */
    public function sendMedia(string $to, string $type, string $link, ?string $caption = null, ?string $filename = null): string
    {
        $media = array_filter(['link' => $link, 'caption' => $type === 'audio' ? null : $caption, 'filename' => $type === 'document' ? $filename : null]);

        return $this->post($to, ['type' => $type, $type => $media]);
    }

    private function post(string $to, array $message): string
    {
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($to, '+')] + $message;
        if ($this->dryRun()) {
            Log::info('whatsapp dry-run', $payload);

            return 'dry-'.Str::uuid();
        }
        $base = rtrim(self::cfg('url', 'NOTIFY_WHATSAPP_URL') ?? 'https://graph.facebook.com/v21.0', '/');
        $r = Http::withToken(self::cfg('token', 'NOTIFY_WHATSAPP_TOKEN'))->acceptJson()->timeout(15)
            ->post("$base/".self::cfg('phone_number_id', 'NOTIFY_WHATSAPP_PHONE_ID').'/messages', $payload)->throw();

        return (string) ($r->json('messages.0.id') ?? 'wa-'.Str::uuid());
    }

    /** Meta signs webhook bodies with the app secret: X-Hub-Signature-256: sha256=<hex hmac>. */
    public static function validSignature(string $body, ?string $header): bool
    {
        $secret = self::cfg('app_secret', 'NOTIFY_WHATSAPP_APP_SECRET');
        if (! $secret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), substr($header, 7));
    }
}

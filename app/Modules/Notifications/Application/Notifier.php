<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;

/**
 * Entry point for sending: picks channels from the recipient's addresses and
 * preferences, renders the template in the recipient's language, writes the
 * outbox rows and queues delivery.
 *
 * Transactional messages are always sent (falling back to any reachable channel
 * if preferences turned them all off); marketing needs guests.marketing_consent.
 */
class Notifier
{
    public function __construct(private TenantContext $ctx) {}

    /**
     * @param  array{guest_id?:int|null,email?:string|null,phone?:string|null,locale?:string|null,name?:string|null}  $to
     * @param  array{type?:string,id?:int,source_event_id?:int}  $ref
     * @return OutboxMessage[]
     */
    public function notify(string $event, array $to, array $vars = [], array $ref = []): array
    {
        $guest = ! empty($to['guest_id']) ? Guest::withoutGlobalScopes()->find($to['guest_id']) : null;
        $hotel = $this->ctx->hotelId() ? Hotel::withoutGlobalScopes()->find($this->ctx->hotelId()) : null;
        $email = $to['email'] ?? $guest?->email;
        $phone = $to['phone'] ?? $guest?->phone;
        $locale = $this->locale($to['locale'] ?? $guest?->language ?? $hotel?->locale);
        $category = DefaultTemplates::category($event);
        $vars += [
            'guest_name' => $to['name'] ?? ($guest ? trim("$guest->first_name $guest->last_name") : ''),
            'hotel_name' => $hotel?->name ?? config('app.name'), 'hotel_phone' => $hotel?->phone ?? '',
            'check_in_time' => $hotel?->check_in_time ?? '14:00',
        ];
        $base = [
            'group_id' => $this->ctx->groupId() ?? $hotel?->group_id, 'hotel_id' => $hotel?->id, 'guest_id' => $guest?->id,
            'event' => $event, 'locale' => $locale, 'category' => $category,
            'ref_type' => $ref['type'] ?? null, 'ref_id' => $ref['id'] ?? null, 'source_event_id' => $ref['source_event_id'] ?? null,
        ];

        $pref = $guest ? NotificationPreference::withoutGlobalScopes()->where('guest_id', $guest->id)->first() : null;
        $allowed = ['email' => $pref?->email ?? true, 'sms' => $pref?->sms ?? true, 'whatsapp' => $pref?->whatsapp ?? false];
        $address = ['email' => $email, 'sms' => $phone, 'whatsapp' => $phone];

        if ($category === 'marketing' && (! $guest?->marketing_consent || $pref?->marketing === false)) {
            return [OutboxMessage::create($base + [
                'channel' => 'none', 'recipient' => $email ?? $phone ?? '-', 'body' => '', 'status' => 'skipped',
                'error' => 'No marketing consent',
            ])];
        }

        $templates = [];
        foreach (DefaultTemplates::CHANNELS as $channel) {
            if ($channel === 'whatsapp' && in_array($event, ['reservation.created', 'reservation.pre_arrival'], true) && WhatsApp\GuestJourney::enabled()) {
                continue; // the WhatsApp journey sends these as Meta templates
            }
            if ($address[$channel] && ($t = $this->template($hotel, $event, $channel, $locale))) {
                $templates[$channel] = $t;
            }
        }
        $chosen = array_filter($templates, fn ($c) => $allowed[$c], ARRAY_FILTER_USE_KEY);
        if (! $chosen && $templates && $category === 'transactional') {
            $chosen = array_slice($templates, 0, 1, true); // transactional always reaches the guest
        }

        $out = [];
        foreach ($chosen as $channel => $t) {
            $m = OutboxMessage::create($base + [
                'channel' => $channel, 'recipient' => $address[$channel],
                'subject' => TemplateRenderer::render($t['subject'], $vars), 'body' => TemplateRenderer::render($t['body'], $vars),
                'status' => 'queued',
            ]);
            SendNotificationJob::dispatch($m->id)->afterCommit();
            $out[] = $m;
        }

        return $out;
    }

    /** Queue a fresh delivery of an existing message (manual resend or retry). */
    public function resend(OutboxMessage $m): void
    {
        $m->update(['status' => 'queued', 'next_attempt_at' => null, 'max_attempts' => max($m->max_attempts, $m->attempts + 1)]);
        SendNotificationJob::dispatch($m->id)->afterCommit();
    }

    private function template(?Hotel $hotel, string $event, string $channel, string $locale): ?array
    {
        foreach (array_unique([$locale, 'fr', 'en']) as $l) {
            if ($hotel) {
                $t = NotificationTemplate::withoutGlobalScopes()->where('hotel_id', $hotel->id)
                    ->where(['event' => $event, 'channel' => $channel, 'locale' => $l])->first();
                if ($t) {
                    if (! $t->active) {
                        return null;
                    }

                    return ['subject' => $t->subject, 'body' => $t->body];
                }
            }
            if ($d = DefaultTemplates::find($event, $channel, $l)) {
                return $d;
            }
        }

        return null;
    }

    private function locale(?string $lang): string
    {
        return str_starts_with(strtolower((string) $lang), 'en') ? 'en' : 'fr';
    }
}

<?php

namespace App\Modules\Notifications\Application\WhatsApp;

use App\Models\User;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppAccount;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppConversation;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppMessage;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppOptOut;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * WhatsApp conversations: outbound template/session/media messages with the
 * 24h customer-service window rule and STOP opt-out, inbound webhook handling
 * and delivery/read statuses.
 */
class WhatsAppMessenger
{
    public const STOP = ['STOP', 'ARRET', 'ARRÊT', 'UNSUBSCRIBE', 'DESABONNER', 'DÉSABONNER'];

    public const START = ['START', 'SUBSCRIBE'];

    private const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    public function __construct(private TenantContext $ctx, private WhatsAppClient $client) {}

    public static function waId(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }

    public function optedOut(string $waId, ?int $groupId = null): bool
    {
        return $this->ctx->withoutScope(fn () => WhatsAppOptOut::where('group_id', $groupId ?? $this->ctx->groupId())->where('wa_id', $waId)->exists());
    }

    /** Hotel must be in context. */
    public function conversationFor(string $waId, ?Guest $guest = null, ?Reservation $r = null, ?string $name = null): WhatsAppConversation
    {
        $conv = WhatsAppConversation::where('wa_id', $waId)->where('reservation_id', $r?->id)->latest('id')->first();
        if ($conv) {
            if ($conv->status === 'closed') {
                $conv->update(['status' => 'open']);
            }

            return $conv;
        }

        return WhatsAppConversation::create([
            'wa_id' => $waId, 'guest_id' => $guest?->id, 'reservation_id' => $r?->id,
            'name' => $name ?? ($guest ? trim("$guest->first_name $guest->last_name") : null),
            'locale' => self::locale($guest?->language), 'status' => 'open',
        ]);
    }

    /**
     * Automated journey step for a reservation (hotel in context). Requires the guest's
     * WhatsApp opt-in, honours STOP, is idempotent per step and stay, and uses a template
     * outside the 24h window (session text inside it).
     */
    public function journey(string $journey, Reservation $r, array $vars = [], bool $once = true): ?WhatsAppMessage
    {
        $guest = Guest::withoutGlobalScopes()->find($r->guest_id);
        $waId = self::waId($guest?->phone);
        if (! $guest || strlen($waId) < 8) {
            return null;
        }
        $pref = NotificationPreference::withoutGlobalScopes()->where('guest_id', $guest->id)->first();
        if (! $pref?->whatsapp || $this->optedOut($waId, $r->group_id)) {
            return null;
        }
        if ($once && WhatsAppMessage::where('journey', $journey)->where('reservation_id', $r->id)->where('status', '!=', 'failed')->exists()) {
            return null;
        }
        $hotel = Hotel::withoutGlobalScopes()->find($r->hotel_id);
        $vars += ['guest_name' => trim("$guest->first_name $guest->last_name"), 'hotel_name' => $hotel?->name, 'number' => $r->number,
            'arrival' => $r->arrival?->toDateString(), 'departure' => $r->departure?->toDateString()];

        return $this->sendTemplate($this->conversationFor($waId, $guest, $r), $journey, $vars, null, $r->id);
    }

    /** Template message (or session text when the window is open). */
    public function sendTemplate(WhatsAppConversation $conv, string $journey, array $vars = [], ?User $by = null, ?int $reservationId = null): WhatsAppMessage
    {
        $this->assertNotOptedOut($conv);
        $tpl = TemplateRegistry::find($journey, $conv->locale) ?? throw new DomainException("No WhatsApp template for $journey.", 'no-template');
        $body = TemplateRegistry::render($tpl, $vars);
        $base = ['journey' => $journey, 'reservation_id' => $reservationId ?? $conv->reservation_id, 'sent_by' => $by?->id, 'body' => $body];

        if ($conv->windowOpen()) {
            return $this->record($conv, $base + ['type' => 'text'], fn () => $this->client->sendText($conv->wa_id, $body));
        }
        if (! $this->client->dryRun() && $tpl->meta_status !== 'approved') {
            return $this->record($conv, $base + ['type' => 'template', 'template_name' => $tpl->name, 'status' => 'blocked',
                'error' => "Template $tpl->name ($tpl->locale) is not approved by Meta"]);
        }

        return $this->record($conv, $base + ['type' => 'template', 'template_name' => $tpl->name],
            fn () => $this->client->sendTemplate($conv->wa_id, $tpl->name, $tpl->locale, TemplateRegistry::params($tpl, $vars)));
    }

    /** Free-form staff reply: only inside the 24h window. */
    public function reply(WhatsAppConversation $conv, string $text, ?User $by = null): WhatsAppMessage
    {
        $this->assertSession($conv);

        return $this->record($conv, ['type' => 'text', 'body' => $text, 'sent_by' => $by?->id], fn () => $this->client->sendText($conv->wa_id, $text));
    }

    public function sendMedia(WhatsAppConversation $conv, string $type, string $link, ?string $caption = null, ?User $by = null): WhatsAppMessage
    {
        $this->assertSession($conv);

        return $this->record($conv, ['type' => $type, 'body' => $caption, 'media' => ['link' => $link], 'sent_by' => $by?->id],
            fn () => $this->client->sendMedia($conv->wa_id, $type, $link, $caption, basename(parse_url($link, PHP_URL_PATH) ?: 'file')));
    }

    /** Handle a verified Meta webhook payload. Returns counts of processed messages and statuses. */
    public function receive(array $payload): array
    {
        $done = ['messages' => 0, 'statuses' => 0];
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        try {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $v = $change['value'] ?? [];
                    $account = $this->ctx->withoutScope(fn () => WhatsAppAccount::where('phone_number_id', (string) ($v['metadata']['phone_number_id'] ?? ''))->where('active', true)->first());
                    if (! $account) {
                        continue;
                    }
                    $this->ctx->forHotel(Hotel::withoutGlobalScopes()->findOrFail($account->hotel_id));
                    $names = collect($v['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);
                    foreach ($v['messages'] ?? [] as $m) {
                        $this->inbound($m, $names[$m['from'] ?? ''] ?? null);
                        $done['messages']++;
                    }
                    foreach ($v['statuses'] ?? [] as $s) {
                        $this->status($s);
                        $done['statuses']++;
                    }
                }
            }
        } finally {
            $this->ctx->set(...$saved);
        }

        return $done;
    }

    private function inbound(array $m, ?string $name): void
    {
        if (! empty($m['id']) && WhatsAppMessage::where('wamid', $m['id'])->exists()) {
            return; // webhook retry
        }
        $waId = self::waId($m['from'] ?? '');
        $guest = Guest::where(fn ($q) => $q->whereRaw("regexp_replace(coalesce(phone, ''), '\\D', '', 'g') = ?", [$waId]))->first();
        $r = $guest ? $this->currentStay($guest) : null;
        $conv = $this->conversationFor($waId, $guest, $r, $name);
        $type = $m['type'] ?? 'text';
        $body = match ($type) {
            'text' => $m['text']['body'] ?? '',
            'button' => $m['button']['text'] ?? '',
            'interactive' => $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '',
            'location' => trim(($m['location']['name'] ?? '').' '.($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? '')),
            default => $m[$type]['caption'] ?? null,
        };
        $media = in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true)
            ? array_intersect_key($m[$type] ?? [], array_flip(['id', 'mime_type', 'filename', 'sha256'])) : null;
        WhatsAppMessage::create([
            'conversation_id' => $conv->id, 'direction' => 'in', 'type' => $type, 'body' => $body, 'media' => $media,
            'wamid' => $m['id'] ?? null, 'status' => 'received', 'reservation_id' => $conv->reservation_id,
        ]);
        $at = isset($m['timestamp']) ? now()->setTimestamp((int) $m['timestamp']) : now();
        $conv->forceFill(['last_inbound_at' => $at->min(now()), 'last_message_at' => now(), 'unread' => $conv->unread + 1, 'status' => 'open'])->save();

        $keyword = mb_strtoupper(trim((string) $body));
        if (in_array($keyword, self::STOP, true)) {
            $this->optOut($conv, $guest, $keyword);
        } elseif (in_array($keyword, self::START, true)) {
            $this->ctx->withoutScope(fn () => WhatsAppOptOut::where('group_id', $conv->group_id)->where('wa_id', $waId)->delete());
            if ($guest) {
                NotificationPreference::withoutGlobalScopes()->updateOrCreate(['guest_id' => $guest->id], ['group_id' => $guest->group_id, 'whatsapp' => true]);
            }
        }
    }

    private function optOut(WhatsAppConversation $conv, ?Guest $guest, string $keyword): void
    {
        // Confirmation goes out first (the guest just wrote, so the window is open).
        $tpl = TemplateRegistry::find('opt_out', $conv->locale);
        if ($tpl) {
            $body = TemplateRegistry::render($tpl, []);
            $this->record($conv, ['type' => 'text', 'body' => $body, 'journey' => 'opt_out'], fn () => $this->client->sendText($conv->wa_id, $body));
        }
        $this->ctx->withoutScope(fn () => WhatsAppOptOut::updateOrCreate(
            ['group_id' => $conv->group_id, 'wa_id' => $conv->wa_id], ['keyword' => mb_substr($keyword, 0, 16), 'opted_out_at' => now()]));
        if ($guest) {
            NotificationPreference::withoutGlobalScopes()->updateOrCreate(['guest_id' => $guest->id], ['group_id' => $guest->group_id, 'whatsapp' => false, 'marketing' => false]);
        }
    }

    private function status(array $s): void
    {
        $msg = WhatsAppMessage::where('wamid', $s['id'] ?? '')->first();
        $new = $s['status'] ?? '';
        if (! $msg) {
            return;
        }
        if ($new === 'failed') {
            $msg->update(['status' => 'failed', 'error' => mb_substr((string) ($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'failed'), 0, 250)]);

            return;
        }
        if (! isset(self::RANK[$new]) || (self::RANK[$msg->status] ?? -1) >= self::RANK[$new]) {
            return; // statuses can arrive out of order
        }
        $msg->update(['status' => $new] + match ($new) {
            'delivered' => ['delivered_at' => now()],
            'read' => ['read_at' => now(), 'delivered_at' => $msg->delivered_at ?? now()],
            default => [],
        });
    }

    private function currentStay(Guest $guest): ?Reservation
    {
        return Reservation::where('guest_id', $guest->id)->where('status', 'checked_in')->latest('arrival')->first()
            ?? Reservation::where('guest_id', $guest->id)->whereIn('status', ['confirmed', 'tentative'])->whereDate('arrival', '>=', now()->subDay())->orderBy('arrival')->first()
            ?? Reservation::where('guest_id', $guest->id)->where('status', 'checked_out')->whereDate('departure', '>=', now()->subDays(7))->latest('departure')->first();
    }

    private function assertNotOptedOut(WhatsAppConversation $conv): void
    {
        if ($this->optedOut($conv->wa_id, $conv->group_id)) {
            throw new DomainException('This guest has opted out of WhatsApp (STOP).', 'opted-out');
        }
    }

    private function assertSession(WhatsAppConversation $conv): void
    {
        $this->assertNotOptedOut($conv);
        if (! $conv->windowOpen()) {
            throw new DomainException('The 24-hour window is closed: send an approved template instead.', 'outside-window');
        }
    }

    private function record(WhatsAppConversation $conv, array $attrs, ?callable $send = null): WhatsAppMessage
    {
        if ($conv->channel === 'web') {
            // Reception chat from the member portal: stored for the portal to poll, never sent through WhatsApp.
            $send = null;
            $attrs = ['status' => 'sent', 'dry_run' => false] + $attrs;
        }
        $msg = WhatsAppMessage::create($attrs + [
            'conversation_id' => $conv->id, 'direction' => 'out', 'status' => 'queued', 'dry_run' => $this->client->dryRun(),
            'reservation_id' => $conv->reservation_id,
        ]);
        if ($send && $msg->status === 'queued') {
            try {
                $msg->update(['wamid' => $send(), 'status' => 'sent']);
            } catch (Throwable $e) {
                $msg->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
            }
        }
        $conv->forceFill(['last_message_at' => now()])->save();

        return $msg;
    }

    private static function locale(?string $lang): string
    {
        return str_starts_with(strtolower((string) $lang), 'en') ? 'en' : 'fr';
    }
}

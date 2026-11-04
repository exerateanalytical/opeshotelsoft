<?php

namespace App\Modules\Notifications\Http;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Application\SendNotificationJob;
use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Application\WhatsApp\WhatsAppMessenger;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Bulk message to the guests of selected reservations, one message per guest on
 * their preferred channel (WhatsApp if opted in, else email, else SMS). Reuses the
 * notification outbox + SendNotificationJob delivery pipeline.
 */
class BulkMessageController
{
    public const MAX = 100;

    public function __construct(private TenantContext $ctx, private WhatsAppMessenger $wa) {}

    public function send(Request $request)
    {
        $u = $request->user();
        abort_unless($u->can('messaging.reply') || $u->can('messaging.manage'), 403);
        $hotelId = $this->ctx->requireHotelId();
        $data = $request->validate([
            'reservation_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'reservation_ids.*' => ['integer', 'distinct'],
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'in:transactional,marketing'],
        ]);
        $category = $data['category'] ?? 'transactional';
        $hotel = Hotel::withoutGlobalScopes()->find($hotelId);
        $reservations = Reservation::with('room:id,number')->whereIn('id', $data['reservation_ids'])->get()->keyBy('id');

        $results = [];
        $seen = [];
        foreach ($data['reservation_ids'] as $id) {
            $r = $reservations->get($id);
            $row = ['reservation_id' => $id, 'number' => $r?->number, 'guest_id' => $r?->guest_id, 'guest' => null, 'channel' => null];
            if (! $r) {
                $results[] = $row + ['status' => 'failed', 'reason' => 'not_found'];

                continue;
            }
            $guest = $r->guest_id ? Guest::find($r->guest_id) : null;
            $row['guest'] = $guest ? trim("$guest->first_name $guest->last_name") : null;
            if (! $guest) {
                $results[] = $row + ['status' => 'skipped', 'reason' => 'no_contact'];

                continue;
            }
            if (isset($seen[$guest->id])) {
                $results[] = $row + ['status' => 'skipped', 'reason' => 'duplicate_guest'];

                continue;
            }
            $seen[$guest->id] = true;

            $pref = NotificationPreference::where('guest_id', $guest->id)->first();
            if ($category === 'marketing' && (! $guest->marketing_consent || $pref?->marketing === false)) {
                $results[] = $row + ['status' => 'skipped', 'reason' => 'no_consent'];

                continue;
            }
            [$channel, $address] = $this->channel($guest, $pref, $r->group_id);
            if (! $channel) {
                $results[] = $row + ['status' => 'skipped', 'reason' => ($guest->email || $guest->phone) ? 'no_consent' : 'no_contact'];

                continue;
            }
            $vars = [
                'guest_name' => $row['guest'], 'first_name' => $guest->first_name, 'number' => $r->number,
                'arrival' => $r->arrival?->toDateString(), 'departure' => $r->departure?->toDateString(), 'room' => $r->room?->number ?? '',
                'hotel_name' => $hotel?->name ?? config('app.name'), 'hotel_phone' => $hotel?->phone ?? '',
            ];
            try {
                $m = OutboxMessage::create([
                    'group_id' => $r->group_id, 'hotel_id' => $hotelId, 'guest_id' => $guest->id, 'event' => 'messaging.bulk',
                    'locale' => str_starts_with(strtolower((string) $guest->language), 'en') ? 'en' : 'fr', 'category' => $category,
                    'ref_type' => 'reservation', 'ref_id' => $r->id, 'channel' => $channel, 'recipient' => $address,
                    'subject' => TemplateRenderer::render($data['subject'] ?? null, $vars) ?? ($hotel?->name ?? config('app.name')),
                    'body' => TemplateRenderer::render($data['body'], $vars), 'status' => 'queued',
                ]);
                SendNotificationJob::dispatch($m->id)->afterCommit();
                $results[] = array_merge($row, ['channel' => $channel, 'status' => 'queued', 'reason' => null, 'message_id' => $m->id]);
            } catch (\Throwable $e) {
                report($e);
                $results[] = $row + ['status' => 'failed', 'reason' => 'send_error'];
            }
        }

        $summary = collect($results)->countBy('status')->all() + ['queued' => 0, 'skipped' => 0, 'failed' => 0];
        activity()->causedBy($u)->event('messaging.bulk')
            ->withProperties(['hotel_id' => $hotelId, 'category' => $category, 'recipients' => count($data['reservation_ids']), 'summary' => $summary,
                'reservation_ids' => $data['reservation_ids']])
            ->log('Bulk guest message');

        return response()->json(['summary' => $summary, 'results' => $results]);
    }

    /** @return array{0:?string,1:?string} */
    private function channel(Guest $g, ?NotificationPreference $pref, ?int $groupId): array
    {
        $waId = WhatsAppMessenger::waId($g->phone);
        if ($pref?->whatsapp && strlen($waId) >= 8 && ! $this->wa->optedOut($waId, $groupId)) {
            return ['whatsapp', $g->phone];
        }
        if ($g->email && ($pref?->email ?? true)) {
            return ['email', $g->email];
        }
        if ($g->phone && ($pref?->sms ?? true)) {
            return ['sms', $g->phone];
        }

        return [null, null];
    }
}

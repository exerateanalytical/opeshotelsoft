<?php

namespace App\Modules\MemberPortal\Application;

use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppConversation;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppMessage;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;

/**
 * Reception chat. A web conversation lives in the Notifications tables (channel `web`, wa_id `web-<guest_id>`), so the
 * staff see it in the normal /messaging inbox and their replies come back here (the portal polls). Each member
 * message raises `messaging.guest_message`.
 */
class MemberChat
{
    public function __construct(private HotelScope $scope, private EventBus $bus) {}

    /** The hotel to talk to: the requested one (must be in the group), else current or next stay, else any. */
    public function hotelFor(MemberAccount $a, ?int $hotelId): Hotel
    {
        if ($hotelId) {
            return $this->scope->hotel($hotelId);
        }
        $r = Reservation::where('guest_id', $a->guest_id)->whereIn('status', ['checked_in', 'confirmed', 'tentative'])->orderByRaw("status = 'checked_in' desc")->orderBy('arrival')->first();

        return $this->scope->hotel($r?->hotel_id ?? $a->membership()?->enrolled_hotel_id ?? Hotel::where('status', 'active')->orderBy('id')->value('id') ?? 0);
    }

    public function thread(MemberAccount $a, Hotel $hotel, int $after = 0): array
    {
        return $this->scope->run($hotel, function () use ($a, $hotel, $after) {
            $conv = $this->find($a);
            if (! $conv) {
                return ['hotel' => $hotel->only(['id', 'code', 'name']), 'messages' => [], 'last_id' => 0];
            }
            // The member is looking at the thread: staff replies count as read.
            WhatsAppMessage::where('conversation_id', $conv->id)->where('direction', 'out')->whereNull('read_at')->update(['read_at' => now(), 'delivered_at' => now(), 'status' => 'read']);
            $rows = WhatsAppMessage::where('conversation_id', $conv->id)->where('id', '>', $after)->orderBy('id')->limit(200)->get(['id', 'direction', 'body', 'created_at']);

            return [
                'hotel' => $hotel->only(['id', 'code', 'name']),
                'messages' => $rows->map(fn ($m) => ['id' => $m->id, 'from' => $m->direction === 'in' ? 'me' : 'reception', 'body' => $m->body, 'at' => $m->created_at->toIso8601String()])->all(),
                'last_id' => (int) ($rows->last()?->id ?? $after),
            ];
        });
    }

    public function send(MemberAccount $a, Hotel $hotel, string $text): array
    {
        return $this->scope->run($hotel, function () use ($a, $hotel, $text) {
            $stay = Reservation::with('room:id,number')->where('guest_id', $a->guest_id)->where('hotel_id', $hotel->id)->whereIn('status', ['checked_in', 'confirmed', 'tentative'])
                ->orderByRaw("status = 'checked_in' desc")->orderBy('arrival')->first();
            $conv = $this->find($a) ?? WhatsAppConversation::create([
                'channel' => 'web', 'wa_id' => 'web-'.$a->guest_id, 'guest_id' => $a->guest_id, 'name' => trim("{$a->guest?->first_name} {$a->guest?->last_name}"),
                'locale' => $a->locale, 'status' => 'open',
            ]);
            $conv->forceFill([
                'reservation_id' => $stay?->id ?? $conv->reservation_id, 'status' => 'open', 'last_inbound_at' => now(), 'last_message_at' => now(), 'unread' => $conv->unread + 1,
            ])->save();
            $msg = WhatsAppMessage::create(['conversation_id' => $conv->id, 'direction' => 'in', 'type' => 'text', 'body' => $text, 'status' => 'received', 'reservation_id' => $conv->reservation_id]);
            $this->bus->record('messaging.guest_message', [
                'hotel_id' => $hotel->id, 'conversation_id' => $conv->id, 'guest_id' => $a->guest_id,
                'room_number' => $stay?->status === 'checked_in' ? $stay->room?->number : null, 'text' => mb_substr($text, 0, 120),
            ], 'whatsapp_conversation', $conv->id);

            return ['id' => $msg->id, 'from' => 'me', 'body' => $msg->body, 'at' => $msg->created_at->toIso8601String()];
        });
    }

    /** The member's web conversation at the hotel in context. */
    private function find(MemberAccount $a): ?WhatsAppConversation
    {
        return WhatsAppConversation::where('channel', 'web')->where('guest_id', $a->guest_id)->latest('id')->first();
    }
}

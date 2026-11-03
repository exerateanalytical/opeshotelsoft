<?php

namespace App\Modules\MemberPortal\Application;

use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Domain\DomainException;
use Throwable;

/**
 * In-stay requests (towels, cleaning, maintenance, room service, reception). Calls the ServiceRequests module
 * (ServiceRequestService::create). Until that class exists the request is sent to reception through the chat, so it is never lost.
 */
class MemberRequests
{
    public const CATEGORIES = [
        'housekeeping' => ['towels', 'pillows', 'blankets', 'soap', 'shampoo', 'toilet_paper', 'cleaning', 'extra_bed', 'water', 'iron'],
        'maintenance' => ['ac', 'light', 'plumbing', 'tv', 'wifi'],
        'room_service' => ['breakfast', 'meal', 'drinks', 'ice'],
        'reception' => ['call_reception'],
        'concierge' => ['taxi', 'wake_up'],
        'laundry' => ['laundry_pickup'],
        'other' => [],
    ];

    private const SERVICE = 'App\\Modules\\ServiceRequests\\Application\\ServiceRequestService';

    private const MODEL = 'App\\Modules\\ServiceRequests\\Domain\\ServiceRequest';

    public function __construct(private HotelScope $scope, private MemberChat $chat) {}

    public static function available(): bool
    {
        return class_exists(self::SERVICE);
    }

    public function create(MemberAccount $a, string $category, array $items, ?string $note): array
    {
        $stay = Reservation::with('room:id,number')->where('guest_id', $a->guest_id)->where('status', 'checked_in')->latest('arrival')->first()
            ?? throw new DomainException('Requests are available during your stay.', 'not-in-house');
        $hotel = $this->scope->hotel($stay->hotel_id);
        $room = (string) ($stay->room?->number ?? '');

        if (self::available()) {
            $r = $this->scope->run($hotel, fn () => app(self::SERVICE)->create([
                'hotel_id' => $hotel->id, 'reservation_id' => $stay->id, 'room_number' => $room, 'guest_id' => $a->guest_id,
                'category' => $category, 'items' => $items, 'note' => $note, 'source' => 'member_portal',
            ]));

            return ['id' => $r->id, 'status' => $r->status ?? 'new', 'via' => 'service_requests'];
        }
        $text = "[$category] ".implode(', ', $items).($note ? " - $note" : '');
        $this->chat->send($a, $hotel, $text);

        return ['id' => null, 'status' => 'new', 'via' => 'chat'];
    }

    public function list(MemberAccount $a): array
    {
        if (! class_exists(self::MODEL)) {
            return [];
        }
        try {
            return (self::MODEL)::where('guest_id', $a->guest_id)->orderByDesc('id')->limit(50)->get()
                ->map(fn ($r) => ['id' => $r->id, 'category' => $r->category, 'items' => $r->items, 'note' => $r->note, 'status' => $r->status, 'room_number' => $r->room_number, 'at' => $r->created_at?->toIso8601String()])->all();
        } catch (Throwable) {
            return [];
        }
    }
}

<?php

namespace App\Modules\ServiceRequests\Application;

use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use App\Modules\ServiceRequests\Domain\VoiceEvent;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Guest and staff requests for a room: create (with dedupe), route to a department, and the status flow. */
class ServiceRequestService
{
    /** Identical open requests from the same room inside this window are folded into the first one. */
    public const DEDUPE_MINUTES = 5;

    private const TRANSITIONS = [
        'acknowledge' => ['new'],
        'assign' => ['new', 'acknowledged', 'in_progress'],
        'start' => ['new', 'acknowledged'],
        'complete' => ['new', 'acknowledged', 'in_progress'],
        'cancel' => ['new', 'acknowledged', 'in_progress'],
    ];

    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    /**
     * Contract with the member portal (M1).
     *
     * @param  array{hotel_id:int,reservation_id?:?int,room_number:string,guest_id?:?int,category:string,items?:array,note?:?string,source:string,priority?:?string}  $data
     */
    public function create(array $data): ServiceRequest
    {
        $hotelId = (int) ($data['hotel_id'] ?? $this->ctx->requireHotelId());

        // The member portal calls without a staff tenant: run in the hotel's context for the duration of the call.
        if ($this->ctx->hotelId() !== $hotelId) {
            $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
            $hotel = $this->ctx->withoutScope(fn () => Hotel::findOrFail($hotelId));
            $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $saved[3]);
            try {
                return $this->create($data);
            } finally {
                $this->ctx->set(...$saved);
            }
        }

        $category = (string) ($data['category'] ?? '');
        if (! isset(Catalog::CATEGORIES[$category])) {
            throw new DomainException('Unknown request category.', 'invalid-category');
        }
        $source = (string) ($data['source'] ?? 'staff');
        if (! in_array($source, Catalog::SOURCES, true)) {
            throw new DomainException('Unknown request source.', 'invalid-source');
        }
        $priority = (string) ($data['priority'] ?? 'normal');
        if (! in_array($priority, Catalog::PRIORITIES, true)) {
            throw new DomainException('Unknown priority.', 'invalid-priority');
        }
        $room = trim((string) ($data['room_number'] ?? ''));
        if ($room === '') {
            throw new DomainException('A room number is required.', 'room-required');
        }
        $items = $this->normaliseItems($data['items'] ?? []);

        return DB::transaction(function () use ($data, $hotelId, $category, $source, $priority, $room, $items) {
            $roomModel = Room::where('number', $room)->first();
            $reservationId = $data['reservation_id'] ?? null;
            $guestId = $data['guest_id'] ?? null;

            if ($source === 'member_portal') {
                $stay = $this->inHouseStay($room, $reservationId, $guestId);
                if (! $stay) {
                    throw new DomainException('Requests from the member portal are only accepted during a stay.', 'not-in-house');
                }
                $reservationId = $stay->id;
                $guestId = $stay->guest_id;
            }

            // Same room, same category, same items, still open, created in the last few minutes: return the first one.
            $recent = ServiceRequest::where('room_number', $room)->where('category', $category)
                ->whereIn('status', ServiceRequest::OPEN)
                ->where('created_at', '>=', now()->subMinutes(self::DEDUPE_MINUTES))
                ->orderBy('id')->get()
                ->first(fn (ServiceRequest $r) => $this->fingerprint($r->items) === $this->fingerprint($items));
            if ($recent) {
                return $recent;
            }

            $sla = Catalog::sla()[$category];
            $minutes = $priority === 'urgent' ? max(1, intdiv($sla, 2)) : $sla;
            $department = Catalog::department($category);
            $request = ServiceRequest::create([
                'reservation_id' => $reservationId, 'room_id' => $roomModel?->id, 'room_number' => $room, 'guest_id' => $guestId,
                'category' => $category, 'department' => $department, 'items' => $items,
                'note' => ($data['note'] ?? null) !== null ? mb_substr(trim((string) $data['note']), 0, 1000) : null,
                'priority' => $priority, 'source' => $source, 'status' => 'new', 'created_by' => $this->ctx->actor()?->id,
                'due_at' => now()->addMinutes($minutes),
            ]);

            VoiceEvent::create([
                'kind' => 'request', 'service_request_id' => $request->id, 'room_number' => $room, 'department' => $department,
                'payload' => ['category' => $category, 'items' => $items, 'note' => $request->note, 'priority' => $priority, 'source' => $source],
            ]);
            $this->bus->record('servicerequests.created', [
                'id' => $request->id, 'hotel_id' => $hotelId, 'room_number' => $room, 'category' => $category, 'department' => $department,
                'items' => $items, 'note' => $request->note, 'priority' => $priority, 'source' => $source,
            ], 'service_request', $request->id);

            return $request->refresh();
        });
    }

    public function acknowledge(ServiceRequest $r): ServiceRequest
    {
        $this->guard($r, 'acknowledge');
        $r->update(['status' => 'acknowledged', 'acknowledged_by' => $this->ctx->actor()?->id, 'acknowledged_at' => now()]);
        $this->emit('acknowledged', $r);

        return $r;
    }

    public function assign(ServiceRequest $r, int $userId): ServiceRequest
    {
        $this->guard($r, 'assign');
        $r->update(['assigned_to' => $userId] + ($r->status === 'new'
            ? ['status' => 'acknowledged', 'acknowledged_by' => $this->ctx->actor()?->id, 'acknowledged_at' => now()] : []));
        $this->emit('assigned', $r, ['assigned_to' => $userId]);

        return $r;
    }

    public function start(ServiceRequest $r): ServiceRequest
    {
        $this->guard($r, 'start');
        $r->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_to' => $r->assigned_to ?? $this->ctx->actor()?->id]
            + ($r->acknowledged_at ? [] : ['acknowledged_by' => $this->ctx->actor()?->id, 'acknowledged_at' => now()]));
        $this->emit('started', $r);

        return $r;
    }

    public function complete(ServiceRequest $r, ?string $note = null): ServiceRequest
    {
        $this->guard($r, 'complete');
        $r->update(['status' => 'done', 'completed_at' => now(), 'completion_note' => $note]
            + ($r->acknowledged_at ? [] : ['acknowledged_by' => $this->ctx->actor()?->id, 'acknowledged_at' => now()]));
        $this->emit('completed', $r);

        return $r;
    }

    public function cancel(ServiceRequest $r, ?string $note = null): ServiceRequest
    {
        $this->guard($r, 'cancel');
        $r->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completion_note' => $note]);
        $this->emit('cancelled', $r);

        return $r;
    }

    private function guard(ServiceRequest $r, string $action): void
    {
        if (! in_array($r->status, self::TRANSITIONS[$action], true)) {
            throw new DomainException("Cannot {$action} a request that is {$r->status}.", 'invalid-state');
        }
    }

    private function emit(string $what, ServiceRequest $r, array $extra = []): void
    {
        $this->bus->record("servicerequests.$what", ['id' => $r->id, 'room_number' => $r->room_number, 'status' => $r->status] + $extra, 'service_request', $r->id);
    }

    /** Items arrive as codes ("towels"), {code, qty} or {label, qty}; stored as a clean list. */
    private function normaliseItems(mixed $items): array
    {
        $out = [];
        foreach ((array) $items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item) && (($item['code'] ?? '') !== '' || ($item['label'] ?? '') !== '')) {
                $clean = array_filter([
                    'code' => isset($item['code']) ? (string) $item['code'] : null,
                    'label' => isset($item['label']) ? mb_substr((string) $item['label'], 0, 80) : null,
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                ], fn ($v) => $v !== null);
                $out[] = $clean;
            }
        }

        return array_slice($out, 0, 20);
    }

    private function fingerprint(array $items): string
    {
        $norm = array_map(fn ($i) => is_array($i) ? ($i['code'] ?? $i['label']).'x'.($i['qty'] ?? 1) : $i.'x1', $items);
        sort($norm);

        return implode('|', $norm);
    }

    private function inHouseStay(string $room, mixed $reservationId, mixed $guestId): ?Reservation
    {
        $q = Reservation::where('status', 'checked_in')
            ->whereHas('room', fn ($r) => $r->where('number', $room));
        if ($reservationId) {
            $q->where('id', $reservationId);
        }
        if ($guestId) {
            $q->where('guest_id', $guestId);
        }

        return $reservationId || $guestId ? $q->first() : null;
    }
}

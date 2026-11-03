<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\WaitlistEntry;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Waitlist: park a request for a sold-out type, offer it when a room frees up, convert to a booking. */
class WaitlistController
{
    public function __construct(private Inventory $inventory, private EventBus $bus, private TenantContext $ctx) {}

    public function index(Request $request)
    {
        $this->can($request, 'view');
        $q = WaitlistEntry::with(['guest:id,first_name,last_name,phone,email', 'roomType:id,code,name', 'ratePlan:id,code,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', ['waiting', 'offered']))
            ->orderBy('arrival')->orderBy('id');
        $page = $q->paginate(100);
        $page->getCollection()->transform(fn (WaitlistEntry $e) => $e->setAttribute('available', $this->available($e)));

        return $page;
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $hotelId = $this->ctx->requireHotelId();
        $data = $request->validate([
            'guest_id' => ['required', 'integer', 'exists:guests,id'],
            'room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'rate_plan_id' => ['nullable', 'integer', Rule::exists('rate_plans', 'id')->where('hotel_id', $hotelId)],
            'arrival' => ['required', 'date', 'after_or_equal:today'],
            'departure' => ['required', 'date', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'between:1,12'],
            'children' => ['sometimes', 'integer', 'between:0,12'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $entry = WaitlistEntry::create($data + ['status' => 'waiting']);
        $this->bus->record('reservation.waitlist.added', $entry->only(['id', 'guest_id', 'room_type_id']), 'waitlist_entry', $entry->id);

        return response()->json($entry->load('guest', 'roomType'), 201);
    }

    /** POST waitlist/{entry}/offer — only when the type has a free room every night. */
    public function offer(Request $request, WaitlistEntry $entry)
    {
        $this->can($request, 'manage');
        if ($entry->status !== 'waiting') {
            throw new DomainException("This entry is {$entry->status}.", 'invalid-state');
        }
        if (! $this->available($entry)) {
            throw new DomainException('No room of this type is free for every night yet.', 'not-available', 409);
        }
        $entry->update(['status' => 'offered', 'offered_at' => now()]);
        $this->bus->record('reservation.waitlist.offered', ['id' => $entry->id, 'guest_id' => $entry->guest_id], 'waitlist_entry', $entry->id);

        return $entry->fresh(['guest', 'roomType']);
    }

    /** POST waitlist/{entry}/convert — book it through the normal reservation path. */
    public function convert(Request $request, WaitlistEntry $entry, ReservationService $service)
    {
        $this->can($request, 'manage');
        if (! in_array($entry->status, ['waiting', 'offered'], true)) {
            throw new DomainException("This entry is {$entry->status}.", 'invalid-state');
        }
        $data = $request->validate(['rate_plan_id' => [$entry->rate_plan_id ? 'nullable' : 'required', 'integer']]);

        $reservation = DB::transaction(function () use ($entry, $data, $service) {
            $r = $service->create([
                'guest_id' => $entry->guest_id, 'room_type_id' => $entry->room_type_id,
                'rate_plan_id' => $data['rate_plan_id'] ?? $entry->rate_plan_id,
                'arrival' => $entry->arrival->toDateString(), 'departure' => $entry->departure->toDateString(),
                'adults' => $entry->adults, 'children' => $entry->children, 'source' => 'waitlist',
            ]);
            $entry->update(['status' => 'converted', 'reservation_id' => $r->id]);
            $this->bus->record('reservation.waitlist.converted', ['id' => $entry->id, 'reservation_id' => $r->id], 'waitlist_entry', $entry->id);

            return $r;
        });

        return response()->json(['entry' => $entry->fresh(), 'reservation' => $reservation], 201);
    }

    public function cancel(Request $request, WaitlistEntry $entry)
    {
        $this->can($request, 'manage');
        $entry->update(['status' => 'cancelled']);

        return $entry;
    }

    private function available(WaitlistEntry $e): bool
    {
        $last = $e->departure->copy()->subDay()->toDateString();
        $grid = $this->inventory->grid($e->hotel_id, $e->room_type_id, $e->arrival->toDateString(), $last);

        return collect($grid)->every(fn ($d) => $d['available'] > 0);
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('reservations.manage') || ($ability === 'view' && $u->can('reservations.view')), 403);
    }
}

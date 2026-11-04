<?php

namespace App\Modules\GuestPortal\Application;

use App\Modules\GuestPortal\Domain\GuestOrder;
use App\Modules\POS\Application\PosService;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * In-stay ordering from the guest's phone. Each order becomes a POS check on the
 * room-service outlet, fired to the kitchen and charged to the guest folio, all
 * through the POS application service (balanced GL comes from POS/FrontOffice).
 */
class GuestOrderService
{
    public function __construct(private TenantContext $ctx, private PosService $pos, private EventBus $bus) {}

    /** Run $fn with the reservation's hotel in context. */
    public function asHotelOf(Reservation $r, callable $fn): mixed
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->forHotel(Hotel::withoutGlobalScopes()->findOrFail($r->hotel_id));
        try {
            return $fn();
        } finally {
            $this->ctx->set(...$saved);
        }
    }

    public function outlet(): ?Outlet
    {
        $code = config('notifications.whatsapp.order_outlet');

        return Outlet::where('active', true)->when($code, fn ($q) => $q->where('code', $code))
            ->orderByRaw("case kind when 'room_service' then 0 when 'restaurant' then 1 when 'bar' then 2 else 3 end")->orderBy('id')->first();
    }

    /** Menu for the ordering page (hotel in context). */
    public function menu(?Outlet $outlet, string $lang = 'fr'): array
    {
        if (! $outlet) {
            return [];
        }

        return MenuItem::where('active', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
            ->orderBy('category')->orderBy('name')->get()
            ->map(fn (MenuItem $i) => ['id' => $i->id, 'name' => $lang === 'en' && $i->name_en ? $i->name_en : $i->name,
                'category' => $i->category, 'price' => (int) $i->price])->values()->all();
    }

    /**
     * @param  list<array{menu_item_id:int, quantity:int|float, notes?:string|null}>  $items
     */
    public function place(Reservation $r, array $items, ?string $notes = null, ?string $clientUuid = null): GuestOrder
    {
        return $this->asHotelOf($r, function () use ($r, $items, $notes, $clientUuid) {
            if ($clientUuid && ($done = GuestOrder::where('reservation_id', $r->id)->where('client_uuid', $clientUuid)->first())) {
                return $done; // double tap / retry
            }
            $r = Reservation::findOrFail($r->id);
            if ($r->status !== 'checked_in') {
                throw new DomainException('Ordering is open during your stay only.', 'not-in-house');
            }
            $outlet = $this->outlet() ?? throw new DomainException('Room service is not available.', 'no-outlet');
            if (! $items) {
                throw new DomainException('Your order is empty.', 'empty-order');
            }

            return DB::transaction(function () use ($r, $items, $notes, $clientUuid, $outlet) {
                $check = $this->pos->open($outlet->id, ['table_number' => 'Room '.($r->room?->number ?? $r->room_id)]);
                foreach (array_values($items) as $n => $i) {
                    $lineNotes = trim(($i['notes'] ?? '').($n === 0 && $notes ? ' | '.$notes : ''), ' |');
                    $this->pos->addItem($check, (int) $i['menu_item_id'], max(1, (float) $i['quantity']), [], $lineNotes ? mb_substr($lineNotes, 0, 500) : null);
                }
                $this->pos->fire($check);
                $check = $this->pos->chargeToRoom($check->fresh(), $r->id);
                $order = GuestOrder::create([
                    'reservation_id' => $r->id, 'pos_check_id' => $check->id, 'client_uuid' => $clientUuid,
                    'total' => (int) $check->total, 'status' => 'placed', 'notes' => $notes,
                ]);
                $this->bus->record('guest_portal.order_placed', [
                    'order_id' => $order->id, 'reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'check_id' => $check->id,
                    'check_number' => $check->number, 'total' => (int) $check->total, 'currency' => $r->currency ?? 'XAF',
                ], 'guest_order', $order->id);

                return $order;
            });
        });
    }
}

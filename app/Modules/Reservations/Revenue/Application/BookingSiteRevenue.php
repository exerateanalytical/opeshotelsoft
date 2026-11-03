<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Domain\CorporateContract;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;

/**
 * Revenue rules on the public booking site: best-available-rate flags, promo codes and corporate codes.
 * BookingEngineController calls decorate() on the offers it built, and prepare()/finish() around a booking.
 * All of it runs inside the hotel context the controller already set.
 */
class BookingSiteRevenue
{
    public function __construct(private PromoService $promos, private CorporateService $corporate, private Pricing $pricing, private UpsellService $upsell) {}

    /**
     * @param  array<int, array<string,mixed>>  $offers  offers built by the booking engine (public plans)
     * @param  array<string,mixed>  $input  request data (adults, children, promo_code, corporate_code)
     * @return array<int, array<string,mixed>>
     */
    public function decorate(array $offers, Carbon $arrival, Carbon $departure, array $input): array
    {
        $adults = $input['adults'] ?? 1;
        $children = $input['children'] ?? 0;

        if (! empty($input['corporate_code']) && ($contract = $this->corporate->find($input['corporate_code']))) {
            foreach (RoomType::where('active', true)->orderBy('sort')->get() as $type) {
                $a = $this->corporate->availability($contract, $type->id, $arrival, $departure);
                if (! $a['bookable'] || $adults > $type->max_adults || $children > $type->max_children) {
                    continue;
                }
                try {
                    $q = $this->pricing->quote($contract->ratePlan, $type, $arrival, $departure, $adults, $children, $a['enforce_restrictions']);
                } catch (DomainException) {
                    continue;
                }
                $offers[] = [
                    'room_type' => $type->only(['id', 'code', 'name', 'max_adults', 'max_children', 'amenities']),
                    'rate_plan' => $contract->ratePlan->only(['id', 'code', 'name', 'meal_plan', 'refundable', 'cancellation_hours', 'members_only', 'deposit_required', 'deposit_percent']),
                    'rooms_left' => min(max($a['allotment_left'], $a['general_left']), 5), 'total' => $q['total'], 'currency' => $contract->ratePlan->hotel?->currency,
                    'nights' => $q['nights'], 'corporate' => ['code' => $contract->code, 'source' => $a['source'], 'last_room_availability' => $contract->last_room_availability],
                ];
            }
        }

        foreach ($offers as $i => $o) {
            if (empty($input['promo_code'])) {
                break;
            }
            if (isset($o['corporate'])) {
                continue; // negotiated rates are not stacked with promo codes
            }
            try {
                $p = $this->promos->apply($input['promo_code'], $o['room_type']['id'], $o['rate_plan']['id'], $arrival, $departure, $o['total']);
                $offers[$i]['total_before_promo'] = $o['total'];
                $offers[$i]['total'] = $p['total'];
                $offers[$i]['promo'] = ['code' => $p['code'], 'applied' => true, 'discount' => $p['discount']];
            } catch (DomainException $e) {
                $offers[$i]['promo'] = ['code' => strtoupper($input['promo_code']), 'applied' => false, 'message' => $e->getMessage()];
            }
        }

        // Best available rate: the cheapest offer per room type, on final prices.
        $best = [];
        foreach ($offers as $o) {
            $t = $o['room_type']['id'];
            $best[$t] = isset($best[$t]) ? min($best[$t], $o['total']) : $o['total'];
        }
        foreach ($offers as $i => $o) {
            $offers[$i]['best_available_total'] = $best[$o['room_type']['id']];
            $offers[$i]['is_best_available'] = $o['total'] === $best[$o['room_type']['id']];
        }

        return $offers;
    }

    /**
     * Resolve the plan and the extra ReservationService fields for a web booking.
     *
     * @return array{plan: RatePlan, fields: array<string,mixed>, promo: ?array, contract: ?CorporateContract}
     */
    public function prepare(array $data): array
    {
        $arrival = Carbon::parse($data['arrival'])->startOfDay();
        $departure = Carbon::parse($data['departure'])->startOfDay();
        $contract = ! empty($data['corporate_code']) ? $this->corporate->find($data['corporate_code']) : null;
        if (! empty($data['corporate_code']) && ! $contract) {
            throw new DomainException('This corporate code is not valid.', 'corporate-invalid');
        }

        $fields = [];
        if ($contract && (int) $data['rate_plan_id'] === $contract->rate_plan_id) {
            $plan = $contract->ratePlan;
            $fields = $this->corporate->bookingFields($contract, (int) $data['room_type_id'], $arrival, $departure);
        } else {
            $plan = RatePlan::where('public', true)->findOrFail($data['rate_plan_id']);
            $contract = null;
        }

        $promo = null;
        if (! empty($data['promo_code']) && ! $contract) {
            $type = RoomType::findOrFail($data['room_type_id']);
            $quote = $this->pricing->quote($plan, $type, $arrival, $departure, $data['adults'] ?? 1, $data['children'] ?? 0);
            $promo = $this->promos->apply($data['promo_code'], $type->id, $plan->id, $arrival, $departure, $quote['total']);
            $fields['amount_override'] = $promo['total'];
            $fields['source'] = 'promo:'.$promo['code'];
        }

        return ['plan' => $plan, 'fields' => $fields, 'promo' => $promo, 'contract' => $contract];
    }

    /** After the reservation exists: count the promo use and prepare the booking-stage upgrade offers. */
    public function finish(Reservation $r, array $prepared): void
    {
        if ($prepared['promo']) {
            $this->promos->redeem($prepared['promo']['code'], $r, $prepared['promo']['discount']);
        }
        $offers = $this->upsell->offer($r, 'booking', 'web');
        $r->setAttribute('upsell_offers', $offers->map(fn ($o) => [
            'id' => $o->id, 'to_room_type' => RoomType::find($o->to_room_type_id)?->only(['id', 'code', 'name']),
            'price_per_night' => $o->price_per_night, 'nights' => $o->nights, 'total' => $o->total,
        ])->all());
    }
}

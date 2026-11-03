<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Domain\RatePlan;
use Illuminate\Support\Carbon;

/**
 * Single entry point to price a stay with every revenue rule. The reservation screens (R-B) should call this
 * instead of Pricing directly, then pass `fields` to ReservationService::create (it already holds
 * company_id, room_block_id, amount_override and enforce_restrictions for corporate and promo bookings).
 */
class BookingQuoter
{
    public function __construct(private Pricing $pricing, private PromoService $promos, private CorporateService $corporate) {}

    /**
     * @return array{nights: array, total: int, package: ?array, promo: ?array, corporate: ?array, fields: array<string,mixed>}
     */
    public function quote(array $in): array
    {
        $type = RoomType::findOrFail($in['room_type_id']);
        $plan = RatePlan::findOrFail($in['rate_plan_id']);
        $arrival = Carbon::parse($in['arrival'])->startOfDay();
        $departure = Carbon::parse($in['departure'])->startOfDay();
        $adults = $in['adults'] ?? 1;
        $children = $in['children'] ?? 0;

        $fields = [];
        $contract = ! empty($in['corporate_code']) ? $this->corporate->find($in['corporate_code']) : null;
        $corporate = null;
        $enforce = true;
        if ($contract) {
            $plan = $contract->ratePlan;
            $fields = $this->corporate->bookingFields($contract, $type->id, $arrival, $departure);
            $enforce = $fields['enforce_restrictions'];
            $corporate = ['code' => $contract->code, 'source' => $fields['room_block_id'] ? 'allotment' : 'general', 'last_room_availability' => $contract->last_room_availability];
        }

        $q = $this->pricing->quote($plan, $type, $arrival, $departure, $adults, $children, $enforce);
        $pkg = (new StayRules)->package($plan);

        $promo = null;
        if (! $contract && ! empty($in['promo_code'])) {
            $promo = $this->promos->apply($in['promo_code'], $type->id, $plan->id, $arrival, $departure, $q['total']);
            $fields['amount_override'] = $promo['total'];
        }

        return [
            'nights' => $q['nights'], 'total' => $promo['total'] ?? $q['total'], 'total_before_promo' => $q['total'],
            'package' => $pkg ? ['name' => $pkg->name, 'inclusions' => $pkg->inclusions, 'per_night' => (new StayRules)->packageNight($plan, $adults, $children)] : null,
            'promo' => $promo, 'corporate' => $corporate, 'fields' => $fields + ['rate_plan_id' => $plan->id],
        ];
    }
}

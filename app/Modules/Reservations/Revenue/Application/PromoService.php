<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Domain\PromoCode;
use App\Modules\Reservations\Revenue\Domain\PromoRedemption;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Promo codes: validation, discount maths (deterministic) and redemption with a usage cap. */
class PromoService
{
    /** @throws DomainException code "promo-invalid" */
    public function validate(string $code, int $roomTypeId, int $ratePlanId, Carbon $arrival, Carbon $departure, ?Carbon $today = null): PromoCode
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $promo = PromoCode::where('code', strtoupper(trim($code)))->first();
        $fail = fn (string $why) => throw new DomainException($why, 'promo-invalid');
        if (! $promo || ! $promo->active) {
            $fail('This promo code is not valid.');
        }
        if ($promo->book_from && $today->lt($promo->book_from) || $promo->book_to && $today->gt($promo->book_to)) {
            $fail('This promo code cannot be used today.');
        }
        if ($promo->stay_from && $arrival->lt($promo->stay_from) || $promo->stay_to && $departure->copy()->subDay()->gt($promo->stay_to)) {
            $fail('This promo code does not apply to these stay dates.');
        }
        if ((int) $arrival->diffInDays($departure) < $promo->min_nights) {
            $fail("This promo code needs at least {$promo->min_nights} nights.");
        }
        if ($promo->room_type_ids && ! in_array($roomTypeId, $promo->room_type_ids, true)) {
            $fail('This promo code does not apply to this room type.');
        }
        if ($promo->rate_plan_ids && ! in_array($ratePlanId, $promo->rate_plan_ids, true)) {
            $fail('This promo code does not apply to this rate.');
        }
        if ($promo->max_uses !== null && $promo->uses >= $promo->max_uses) {
            $fail('This promo code has been fully used.');
        }

        return $promo;
    }

    public function discount(PromoCode $promo, int $total): int
    {
        $d = $promo->type === 'percent' ? (int) round($total * $promo->value / 100) : (int) round($promo->value);

        return max(0, min($total - 1, $d)); // never free: at least 1 unit is charged
    }

    /** @return array{code:string, name:string, discount:int, total:int, total_before:int} */
    public function apply(string $code, int $roomTypeId, int $ratePlanId, Carbon $arrival, Carbon $departure, int $total, ?Carbon $today = null): array
    {
        $promo = $this->validate($code, $roomTypeId, $ratePlanId, $arrival, $departure, $today);
        $discount = $this->discount($promo, $total);

        return ['code' => $promo->code, 'name' => $promo->name, 'discount' => $discount, 'total' => $total - $discount, 'total_before' => $total];
    }

    /** Count the use and link it to the reservation. Re-checks the cap under a row lock. */
    public function redeem(string $code, Reservation $r, int $discount): PromoRedemption
    {
        return DB::transaction(function () use ($code, $r, $discount) {
            $promo = PromoCode::where('code', strtoupper(trim($code)))->lockForUpdate()->firstOrFail();
            if ($promo->max_uses !== null && $promo->uses >= $promo->max_uses) {
                throw new DomainException('This promo code has been fully used.', 'promo-invalid');
            }
            $promo->increment('uses');

            return PromoRedemption::create(['promo_code_id' => $promo->id, 'reservation_id' => $r->id, 'discount' => $discount]);
        });
    }
}

<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\CheckoutFeeRule;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\ReservationNight;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;

/**
 * Early and late check-out fees. A late rule applies when a guest departing
 * today checks out at or after its hour (the latest matching hour wins); an
 * early rule applies when the guest leaves before the booked departure.
 */
class CheckoutFees
{
    public function __construct(private FolioService $folios) {}

    /** @return array{type:string, early:bool, nights_released:int, fee:?array, fee_posted:bool} */
    public function quote(Reservation $r, ?Carbon $at = null): array
    {
        $hotel = Hotel::findOrFail($r->hotel_id);
        $at ??= now($hotel->timezone ?? 'Africa/Douala');
        $today = $hotel->business_date->copy()->startOfDay();
        $early = $r->departure->gt($today);
        $released = $early ? (int) $today->copy()->addDay()->diffInDays($r->departure) : 0;

        $rule = $early
            ? CheckoutFeeRule::where('kind', 'early')->where('active', true)->orderBy('id')->first()
            : CheckoutFeeRule::where('kind', 'late')->where('active', true)->where('after_hour', '<=', $at->hour)->orderByDesc('after_hour')->first();

        $fee = null;
        if ($rule && (! $early || $released > 0)) {
            $amount = $rule->fee_type === 'fixed' ? (int) $rule->value : (int) round($this->nightRate($r) * $rule->value / 100);
            if ($amount > 0) {
                $fee = [
                    'rule_id' => $rule->id, 'kind' => $rule->kind, 'charge_code' => $rule->charge_code, 'amount' => $amount,
                    'label' => $rule->label ?? ($rule->kind === 'late' ? 'Late check-out' : 'Early departure'),
                ];
            }
        }

        return [
            'type' => $early ? 'early' : ($fee ? 'late' : 'on_time'),
            'early' => $early,
            'nights_released' => $released,
            'fee' => $fee,
            'fee_posted' => $this->posted($r),
        ];
    }

    public function post(Reservation $r): FolioLine
    {
        $r->assertStatus('checked_in');
        if ($this->posted($r)) {
            throw new DomainException('The check-out fee is already posted.', 'fee-already-posted');
        }
        $fee = $this->quote($r)['fee'] ?? throw new DomainException('No check-out fee applies now.', 'no-fee');

        return $this->folios->charge($this->folios->guestFolioFor($r), $fee['charge_code'], $fee['amount'], [
            'description' => $fee['label'], 'source_type' => 'checkout_fee', 'source_id' => $r->id,
        ]);
    }

    public function posted(Reservation $r): bool
    {
        return FolioLine::where('source_type', 'checkout_fee')->where('source_id', (string) $r->id)
            ->where('type', 'charge')->where('reversed', false)->exists();
    }

    private function nightRate(Reservation $r): int
    {
        $night = ReservationNight::where('reservation_id', $r->id)->orderByDesc('date')->first();

        return (int) ($night?->amount ?? 0);
    }
}

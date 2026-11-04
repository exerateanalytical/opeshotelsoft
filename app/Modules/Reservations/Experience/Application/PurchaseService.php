<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\FrontOffice\Application\CheckoutFees;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Domain\ExperienceService;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use App\Modules\Reservations\Experience\Domain\ReservationPurchase;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Things a guest buys for a stay: services, early check-in, late check-out. Early/late are priced by the hotel's
 * checkout fee rules (CheckoutFees). A purchase posts to the guest folio at once for in-house guests, otherwise it
 * waits as "pending" and is posted when the guest checks in (postPending, called from the check-in event).
 * A purchased late check-out is posted with the same source as the front desk's late fee so it is never charged twice.
 */
class PurchaseService
{
    public function __construct(private CheckoutFees $fees, private FolioService $folios) {}

    /** Catalogue for a language: the English name when asked for English and one is set, otherwise the French name. */
    public function services(string $lang = 'fr'): array
    {
        return ExperienceService::where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'name_en', 'price'])
            ->map(fn (ExperienceService $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $lang === 'en' && trim((string) $s->name_en) !== '' ? $s->name_en : $s->name, 'price' => $s->price])->all();
    }

    public function quote(Reservation $r, string $kind, string $time): array
    {
        $hour = (int) substr($time, 0, 2);
        $this->assertBuyable($r, $kind);
        $q = $this->fees->requestQuote($r, $kind, $hour) ?? throw new DomainException('This request is free of charge or not offered; ask the front desk.', 'no-fee', 422);

        return $q + ['time' => $time];
    }

    public function buyRequest(Reservation $r, string $kind, string $time, string $source = 'guest'): ReservationPurchase
    {
        return DB::transaction(function () use ($r, $kind, $time, $source) {
            $q = $this->quote($r, $kind, $time);
            if (ReservationPurchase::where('reservation_id', $r->id)->where('kind', $kind)->exists()) {
                throw new DomainException('You already requested this for the stay.', 'already-purchased', 409);
            }
            $p = ReservationPurchase::create([
                'reservation_id' => $r->id, 'kind' => $kind, 'label' => $q['label'].' '.$time, 'amount' => $q['amount'],
                'charge_code' => $q['charge_code'], 'requested_time' => $time, 'source' => $source,
            ]);

            return $this->postIfInHouse($r, $p);
        });
    }

    public function buyService(Reservation $r, string $code, int $quantity = 1, string $source = 'guest'): ReservationPurchase
    {
        $r->assertStatus('tentative', 'confirmed', 'checked_in');
        $svc = ExperienceService::where('code', $code)->where('active', true)->first() ?? throw new DomainException('Unknown service.', 'unknown-service', 404);

        return DB::transaction(fn () => $this->postIfInHouse($r, ReservationPurchase::create([
            'reservation_id' => $r->id, 'kind' => 'service', 'label' => $svc->name, 'quantity' => $quantity, 'amount' => $svc->price * $quantity,
            'charge_code' => $svc->charge_code, 'source' => $source,
        ])));
    }

    /** Post everything the guest bought before arriving. */
    public function postPending(Reservation $r): int
    {
        $n = 0;
        foreach (ReservationPurchase::where('reservation_id', $r->id)->where('status', 'pending')->get() as $p) {
            $this->post($r, $p);
            $n++;
        }

        return $n;
    }

    private function postIfInHouse(Reservation $r, ReservationPurchase $p): ReservationPurchase
    {
        return $r->status === 'checked_in' ? $this->post($r, $p) : $p;
    }

    private function post(Reservation $r, ReservationPurchase $p): ReservationPurchase
    {
        $line = $this->folios->charge($this->folios->guestFolioFor($r), $p->charge_code, (int) $p->amount, [
            'description' => $p->label, 'quantity' => 1,
            'source_type' => match ($p->kind) {'late_checkout' => 'checkout_fee', 'early_checkin' => 'early_checkin_fee', default => 'guest_purchase'},
            'source_id' => $p->kind === 'service' ? $p->id : $r->id,
        ]);
        $p->update(['status' => 'posted', 'folio_line_id' => $line->id]);

        return $p;
    }

    private function assertBuyable(Reservation $r, string $kind): void
    {
        $s = ExperienceSetting::forCurrentHotel();
        if (! ($kind === 'early_checkin' ? $s->early_checkin_enabled : $s->late_checkout_enabled)) {
            throw new DomainException('This request is not offered by the hotel.', 'not-offered', 422);
        }
        if ($kind === 'early_checkin') {
            $r->assertStatus('tentative', 'confirmed');
        } else {
            $r->assertStatus('tentative', 'confirmed', 'checked_in');
        }
    }
}

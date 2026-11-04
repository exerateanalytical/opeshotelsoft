<?php

namespace App\Modules\GuestPortal\Application;

use App\Modules\FrontOffice\Application\CheckoutFees;
use App\Modules\FrontOffice\Domain\CheckoutFeeRule;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Payments\Application\PayLinkService;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\DigitalCheckin;
use App\Modules\Reservations\Experience\Application\PurchaseService;
use App\Modules\Reservations\Experience\Application\TapeOps;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use App\Modules\Reservations\Experience\Domain\GuestReview;
use App\Modules\Reservations\Experience\Domain\ReservationPurchase;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guest self-service on the manage-booking page (tenant context already set to the booking's hotel):
 * modify dates within the hotel's rules, see the cancellation fee, add services, buy early check-in or late check-out
 * (priced by CheckoutFees), pay the balance by pay link, leave a review.
 */
class ManageService
{
    public function __construct(
        private ReservationService $reservations, private TapeOps $tape, private PurchaseService $purchases, private CheckoutFees $fees,
        private DigitalCheckin $checkin, private EventBus $bus,
    ) {}

    public function options(Reservation $r): array
    {
        $hotel = Hotel::findOrFail($r->hotel_id);
        $upcoming = in_array($r->status, ['tentative', 'confirmed'], true);
        $balance = $this->balance($r);
        $quoteFee = fn (string $kind, int $hour) => $this->safe(fn () => $this->fees->requestQuote($r, $kind, $hour));

        return [
            'modify' => $this->modifyRule($r),
            'cancel' => $upcoming ? ['allowed' => true, 'fee' => $this->reservations->cancellationFee($r), 'currency' => $r->currency] : ['allowed' => false, 'fee' => 0, 'currency' => $r->currency],
            'services' => $r->status === 'cancelled' || in_array($r->status, ['checked_out', 'no_show'], true) ? [] : $this->purchases->services(),
            'early_checkin' => $upcoming && ExperienceSetting::forCurrentHotel()->early_checkin_enabled ? collect(CheckoutFeeRule::where('kind', 'early_checkin')->where('active', true)->orderBy('after_hour')->pluck('after_hour'))
                ->map(fn ($h) => ['hour' => (int) $h, 'fee' => $quoteFee('early_checkin', (int) $h)])->filter(fn ($o) => $o['fee'])->values()->all() : [],
            'late_checkout' => ExperienceSetting::forCurrentHotel()->late_checkout_enabled && in_array($r->status, ['tentative', 'confirmed', 'checked_in'], true) ? collect(CheckoutFeeRule::where('kind', 'late')->where('active', true)->orderBy('after_hour')->pluck('after_hour'))
                ->map(fn ($h) => ['hour' => (int) $h, 'fee' => $quoteFee('late_checkout', (int) $h)])->filter(fn ($o) => $o['fee'])->values()->all() : [],
            'purchases' => ReservationPurchase::where('reservation_id', $r->id)->orderBy('id')->get(['id', 'kind', 'label', 'quantity', 'amount', 'status', 'requested_time'])->all(),
            'balance' => $balance, 'check_in_time' => substr((string) $hotel->check_in_time, 0, 5), 'check_out_time' => substr((string) $hotel->check_out_time, 0, 5),
            'readiness' => $this->checkin->readiness($r),
            'review' => ['allowed' => in_array($r->status, ['checked_in', 'checked_out'], true), 'submitted' => GuestReview::where('reservation_id', $r->id)->exists()],
        ];
    }

    /** Whether and how the guest may change dates. */
    public function modifyRule(Reservation $r): array
    {
        $s = ExperienceSetting::forCurrentHotel();
        $hotel = Hotel::findOrFail($r->hotel_id);
        $base = ['allowed' => false, 'reason' => null, 'extend_only' => ! (bool) $r->ratePlan?->refundable, 'cutoff_hours' => $s->guest_modify_cutoff_hours, 'max_nights' => 30];
        if (! in_array($r->status, ['tentative', 'confirmed'], true)) {
            return $base + ['reason' => 'status'];
        }
        if (! $s->guest_modify) {
            return $base + ['reason' => 'disabled'];
        }
        $deadline = $r->arrival->copy()->setTimeFromTimeString((string) ($hotel->check_in_time ?: '14:00'))->subHours($s->guest_modify_cutoff_hours);
        if (now()->gt($deadline)) {
            return $base + ['reason' => 'cutoff'];
        }

        return ['allowed' => true] + $base;
    }

    /** @return array{applied:bool, old_total:int, new_total:int, difference:int, nights:int} */
    public function modify(Reservation $r, string $arrival, string $departure, bool $apply): array
    {
        $rule = $this->modifyRule($r);
        if (! $rule['allowed']) {
            throw new DomainException(match ($rule['reason']) {
                'cutoff' => "Changes are closed {$rule['cutoff_hours']} hours before arrival. Please contact the hotel.",
                'disabled' => 'Online changes are not available for this hotel. Please contact the hotel.',
                default => 'This booking can no longer be changed online.',
            }, 'modify-closed', 422);
        }
        $from = Carbon::parse($arrival)->startOfDay();
        $to = Carbon::parse($departure)->startOfDay();
        $today = Hotel::findOrFail($r->hotel_id)->business_date->copy()->startOfDay();
        if ($from->lt($today)) {
            throw new DomainException('The arrival date cannot be in the past.', 'invalid-dates', 422);
        }
        if ($to->lte($from) || $from->diffInDays($to) > $rule['max_nights']) {
            throw new DomainException("A stay is between 1 and {$rule['max_nights']} nights.", 'invalid-dates', 422);
        }
        if ($rule['extend_only'] && (! $from->equalTo($r->arrival) || $to->lte($r->departure))) {
            throw new DomainException('This rate is non-refundable: you can only extend your stay (later check-out).', 'non-refundable', 422);
        }
        $old = (int) $r->total_amount;

        DB::beginTransaction();
        try {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            if ($r->room_id && $this->tape->conflicts(\App\Modules\Property\Domain\Room::findOrFail($r->room_id), $from, $to, [$r->id])) {
                $r->update(['room_id' => null]); // the hotel will assign a free room
            }
            $updated = $this->tape->resize($r, $from->toDateString(), $to->toDateString());
            $result = ['applied' => $apply, 'old_total' => $old, 'new_total' => (int) $updated->total_amount, 'difference' => (int) $updated->total_amount - $old, 'nights' => $updated->nightCount()];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $apply ? DB::commit() : DB::rollBack();

        return $result;
    }

    public function balance(Reservation $r): array
    {
        $folio = Folio::where('reservation_id', $r->id)->where('kind', 'guest')->where('status', 'open')->first();
        $svc = app(PayLinkService::class);
        $due = $this->safe(fn () => $folio ? $svc->due('folio', $folio->id) : $svc->due('reservation', $r->id)) ?? 0;

        return ['due' => (int) $due, 'type' => $folio ? 'folio' : 'reservation', 'currency' => $r->currency, 'deposit_required' => (int) $r->deposit_required, 'deposit_paid' => (int) $r->deposit_paid];
    }

    public function payLink(Reservation $r, ?int $amount = null): string
    {
        $b = $this->balance($r);
        $svc = app(PayLinkService::class);
        $folio = $b['type'] === 'folio' ? Folio::where('reservation_id', $r->id)->where('kind', 'guest')->where('status', 'open')->first() : null;
        $link = $svc->create($b['type'] === 'folio' ? 'folio' : 'reservation', $folio?->id ?? $r->id, $amount ? min($amount, $b['due']) : null, ['payer_phone' => $r->guest?->phone, 'valid_days' => 2]);

        return $svc->url($link);
    }

    public function review(Reservation $r, int $rating, ?string $comment): GuestReview
    {
        if (! in_array($r->status, ['checked_in', 'checked_out'], true)) {
            throw new DomainException('You can review your stay once you have arrived.', 'review-closed', 422);
        }
        if (GuestReview::where('reservation_id', $r->id)->exists()) {
            throw new DomainException('Thank you, your review was already received.', 'review-exists', 409);
        }
        $review = GuestReview::create(['reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'rating' => $rating, 'comment' => $comment, 'submitted_at' => now()]);
        $this->bus->record('guest.review_submitted', ['reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'rating' => $rating], 'reservation', $r->id);

        return $review;
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (DomainException) {
            return null;
        }
    }
}

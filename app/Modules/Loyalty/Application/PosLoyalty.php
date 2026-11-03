<?php

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Transaction;
use App\Modules\POS\Domain\Check;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;

/**
 * Loyalty at the point of sale: identify the member on a check (card scan or
 * member search) and take points as a tender. Earning happens on
 * pos.check_settled (LoyaltyModule). The PTS payment posts Dr 419800 points
 * liability / Cr revenue + VAT through the normal POS posting rule.
 */
class PosLoyalty
{
    public function __construct(private LoyaltyService $loyalty, private EventBus $bus, private TenantContext $ctx) {}

    public function attach(Check $check, Membership $m): Check
    {
        $check->assertOpen();
        if ($m->status !== 'active') {
            throw new DomainException('This membership is not active.', 'member-inactive');
        }
        $check->update(['guest_id' => $m->guest_id]);

        return $check->fresh();
    }

    /** Resolve the member for a points tender: reference (card number, QR token or member number), else the check's guest. */
    public function memberFor(Check $check, ?string $reference): Membership
    {
        if ($reference) {
            $m = Membership::where('number', $reference)->first() ?? $this->loyalty->findByCard($reference);
        } else {
            $m = $check->guest_id ? Membership::where('guest_id', $check->guest_id)->first() : null;
        }
        if (! $m) {
            throw new DomainException('Identify the loyalty member (scan the card) before paying with points.', 'member-required');
        }
        if ($m->status !== 'active') {
            throw new DomainException('This membership is not active.', 'member-inactive');
        }
        if ($check->guest_id && $check->guest_id !== $m->guest_id) {
            throw new DomainException('Points can only pay a check of the member themselves.', 'member-mismatch');
        }

        return $m;
    }

    /** Called by PosService::settle for each points payment, inside its transaction. */
    public function redeemTender(Check $check, int $amount, ?string $reference): Transaction
    {
        $m = $this->memberFor($check, $reference);
        $value = max(1, $this->loyalty->program()->point_value);
        if ($amount <= 0 || $amount % $value !== 0) {
            throw new DomainException("A points payment must be a multiple of the point value ($value).", 'points-amount');
        }
        $points = intdiv($amount, $value);
        $m = Membership::lockForUpdate()->findOrFail($m->id);
        if ($points > $m->points_balance) {
            throw new DomainException("Not enough points (balance {$m->points_balance}).", 'insufficient-points');
        }
        $this->loyalty->consumeFifo($m, $points);
        $m->decrement('points_balance', $points);
        if (! $check->guest_id) {
            $check->update(['guest_id' => $m->guest_id]);
        }
        $tx = Transaction::create([
            'loyalty_membership_id' => $m->id, 'hotel_id' => $this->ctx->hotelId(), 'kind' => 'redeem', 'points' => -$points,
            'spend' => $amount, 'description' => "Paiement en points, ticket #{$check->number}",
            'source_type' => 'pos_check', 'source_id' => (string) $check->id, 'user_id' => $this->ctx->actor()?->id,
        ]);
        $this->bus->record('loyalty.points_redeemed_at_pos', ['membership_id' => $m->id, 'transaction_id' => $tx->id, 'points' => $points, 'value' => $amount, 'check_id' => $check->id], 'membership', $m->id);

        return $tx;
    }
}

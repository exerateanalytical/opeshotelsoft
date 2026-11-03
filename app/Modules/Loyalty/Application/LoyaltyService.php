<?php

namespace App\Modules\Loyalty\Application;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Domain\Card;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Program;
use App\Modules\Loyalty\Domain\Tier;
use App\Modules\Loyalty\Domain\Transaction;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Group-wide loyalty: enrol, issue cards, earn on stays and outlet spend at
 * any hotel of the group, redeem points as a folio payment, tier review,
 * FIFO expiry. Points value is accrued as a liability in each hotel's books.
 */
class LoyaltyService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    public function program(): Program
    {
        return Program::with('tiers')->where('active', true)->first()
            ?? throw new DomainException('No loyalty programme is configured for this group.', 'no-program');
    }

    /** Create the default programme (Silver / Gold / Platinum). */
    public function setupDefault(string $name = 'Membership Rewards'): Program
    {
        return DB::transaction(function () use ($name) {
            $program = Program::firstOrCreate([], ['name' => $name]);
            $tiers = [
                ['SILVER', 'Silver', 0, 0, 1.0, ['welcome_drink'], 1],
                ['GOLD', 'Gold', 5_000, 10, 1.25, ['welcome_drink', 'late_checkout_14h', 'room_upgrade_on_availability'], 2],
                ['PLAT', 'Platinum', 15_000, 30, 1.5, ['welcome_drink', 'late_checkout_16h', 'guaranteed_upgrade', 'lounge_access', 'priority_booking'], 3],
            ];
            foreach ($tiers as [$code, $tname, $pts, $nights, $mult, $benefits, $rank]) {
                Tier::firstOrCreate(['loyalty_program_id' => $program->id, 'code' => $code], [
                    'name' => $tname, 'min_qualifying_points' => $pts, 'min_qualifying_nights' => $nights,
                    'earn_multiplier' => $mult, 'benefits' => $benefits, 'rank' => $rank,
                ]);
            }

            return $program->load('tiers');
        });
    }

    public function enrol(Guest $guest, string $cardKind = 'digital'): Membership
    {
        $program = $this->program();
        if ($existing = Membership::where('guest_id', $guest->id)->first()) {
            return $existing->load('tier', 'cards');
        }

        return DB::transaction(function () use ($guest, $program, $cardKind) {
            $base = $program->tiers->sortBy('rank')->first();
            $m = Membership::create([
                'guest_id' => $guest->id, 'number' => $this->memberNumber(), 'loyalty_tier_id' => $base->id,
                'enrolled_hotel_id' => $this->ctx->hotelId(), 'enrolled_at' => now(),
            ]);
            $this->issueCard($m, $cardKind);
            if ($program->enrolment_bonus > 0) {
                $this->credit($m, 'bonus', $program->enrolment_bonus, 0, 0, 0, 'Bonus de bienvenue', 'enrolment', $m->id);
            }
            $this->bus->record('loyalty.member_enrolled', ['membership_id' => $m->id, 'number' => $m->number, 'guest_id' => $guest->id], 'membership', $m->id);

            return $m->load('tier', 'cards');
        });
    }

    public function issueCard(Membership $m, string $kind = 'digital'): Card
    {
        $m->cards()->where('status', 'active')->where('kind', $kind)->update(['status' => 'replaced']);

        return $m->cards()->create([
            'card_number' => $this->cardNumber(), 'kind' => $kind,
            'token' => Str::random(48), 'issued_at' => now(),
        ]);
    }

    public function findByCard(string $numberOrToken): Membership
    {
        $card = Card::where('status', 'active')->where(fn ($q) => $q->where('card_number', $numberOrToken)->orWhere('token', $numberOrToken))->first()
            ?? throw new DomainException('Card not found or not active.', 'card-invalid', 404);

        return Membership::with('guest', 'tier')->findOrFail($card->loyalty_membership_id);
    }

    /** Earn from eligible spend; idempotent per source. */
    public function earn(Membership $m, int $spend, int $nights, string $sourceType, string|int $sourceId, string $description): ?Transaction
    {
        if ($m->status !== 'active' || $spend <= 0) {
            return null;
        }
        if (Transaction::where(['loyalty_membership_id' => $m->id, 'source_type' => $sourceType, 'source_id' => (string) $sourceId, 'kind' => 'earn'])->exists()) {
            return null;
        }
        $program = $this->program();
        $base = intdiv($spend, max(1, $program->spend_per_point));
        $points = (int) floor($base * $m->tier->earn_multiplier);
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($m, $points, $base, $nights, $spend, $sourceType, $sourceId, $description) {
            $tx = $this->credit($m, 'earn', $points, $base, $nights, $spend, $description, $sourceType, $sourceId);
            $m->increment('lifetime_nights', $nights);
            $this->reviewTier($m->fresh());

            return $tx;
        });
    }

    /** Redeem points as a payment on a folio. */
    public function redeemOnFolio(Membership $m, Folio $folio, int $points, FolioService $folios): Transaction
    {
        if ($folio->guest_id !== $m->guest_id) {
            throw new DomainException('Points can only pay the member\'s own folio.');
        }

        return DB::transaction(function () use ($m, $folio, $points, $folios) {
            $m = Membership::lockForUpdate()->findOrFail($m->id);
            if ($points <= 0 || $points > $m->points_balance) {
                throw new DomainException("Not enough points (balance {$m->points_balance}).", 'insufficient-points');
            }
            $value = $points * $this->program()->point_value;
            if ($value > $folio->fresh()->balance) {
                throw new DomainException('Redemption value exceeds the folio balance.');
            }
            $folios->payment($folio, 'PTS', $value, $m->number, ['description' => "Points fidélité ($points pts)"]);
            $this->consumeFifo($m, $points);
            $m->decrement('points_balance', $points);
            $tx = Transaction::create([
                'loyalty_membership_id' => $m->id, 'hotel_id' => $this->ctx->hotelId(), 'kind' => 'redeem', 'points' => -$points,
                'description' => "Utilisation sur folio {$folio->number}", 'source_type' => 'folio', 'source_id' => (string) $folio->id.'-'.now()->timestamp,
                'user_id' => $this->ctx->actor()?->id,
            ]);
            // Liability is released by the PTS payment posting (Dr 419800 / Cr 411100).
            $this->bus->record('loyalty.points_redeemed_on_folio', ['membership_id' => $m->id, 'transaction_id' => $tx->id, 'points' => $points, 'value' => $value, 'folio_id' => $folio->id], 'membership', $m->id);

            return $tx;
        });
    }

    public function adjust(Membership $m, int $points, string $reason): Transaction
    {
        return DB::transaction(function () use ($m, $points, $reason) {
            if ($points < 0) {
                $m = Membership::lockForUpdate()->findOrFail($m->id);
                if (-$points > $m->points_balance) {
                    throw new DomainException('Adjustment would make the balance negative.');
                }
                $this->consumeFifo($m, -$points);
                $m->decrement('points_balance', -$points);

                return Transaction::create(['loyalty_membership_id' => $m->id, 'hotel_id' => $this->ctx->hotelId(), 'kind' => 'adjust', 'points' => $points, 'description' => $reason, 'user_id' => $this->ctx->actor()?->id]);
            }

            return $this->credit($m, 'adjust', $points, 0, 0, 0, $reason, 'adjust', uniqid());
        });
    }

    /** Expire points whose expiry date has passed (FIFO lots). */
    public function expire(): int
    {
        $total = 0;
        $lots = Transaction::whereIn('kind', ['earn', 'bonus', 'adjust'])->where('remaining', '>', 0)->whereDate('expires_on', '<', now())->get();
        foreach ($lots as $lot) {
            DB::transaction(function () use ($lot, &$total) {
                $m = Membership::withoutGlobalScopes()->lockForUpdate()->find($lot->loyalty_membership_id);
                $pts = min($lot->remaining, $m->points_balance);
                $lot->update(['remaining' => 0]);
                if ($pts > 0) {
                    $m->decrement('points_balance', $pts);
                    Transaction::create(['loyalty_membership_id' => $m->id, 'kind' => 'expire', 'points' => -$pts, 'description' => "Expiration lot #{$lot->id}", 'source_type' => 'expiry', 'source_id' => (string) $lot->id]);
                    $total += $pts;
                }
            });
        }

        return $total;
    }

    /** Upgrade (never auto-downgrade mid-year) based on 12-month qualifying activity. */
    public function reviewTier(Membership $m): Membership
    {
        $since = now()->subYear();
        $qp = (int) Transaction::where('loyalty_membership_id', $m->id)->where('kind', 'earn')->where('created_at', '>=', $since)->sum('qualifying_points');
        $qn = (int) Transaction::where('loyalty_membership_id', $m->id)->where('kind', 'earn')->where('created_at', '>=', $since)->sum('nights');
        $current = $m->tier;
        $best = $this->program()->tiers->filter(fn ($t) => ($t->active ?? true) && ($qp >= $t->min_qualifying_points || ($t->min_qualifying_nights > 0 && $qn >= $t->min_qualifying_nights)))
            ->sortByDesc('rank')->first();
        if ($best && $best->rank > $current->rank) {
            $m->update(['loyalty_tier_id' => $best->id, 'tier_reviewed_at' => now()]);
            $this->bus->record('loyalty.tier_changed', ['membership_id' => $m->id, 'from' => $current->code, 'to' => $best->code], 'membership', $m->id);
        }

        return $m->fresh('tier');
    }

    private function credit(Membership $m, string $kind, int $points, int $qualifying, int $nights, int $spend, string $description, string $sourceType, string|int $sourceId): Transaction
    {
        $program = $this->program();
        $tx = Transaction::create([
            'loyalty_membership_id' => $m->id, 'hotel_id' => $this->ctx->hotelId(), 'kind' => $kind, 'points' => $points,
            'qualifying_points' => $qualifying, 'nights' => $nights, 'spend' => $spend, 'remaining' => $points,
            'expires_on' => now()->addMonths($program->expiry_months)->toDateString(), 'description' => $description,
            'source_type' => $sourceType, 'source_id' => (string) $sourceId, 'user_id' => $this->ctx->actor()?->id,
        ]);
        $m->increment('points_balance', $points);
        $m->increment('lifetime_points', $points);
        $this->bus->record('loyalty.points_earned', [
            'membership_id' => $m->id, 'transaction_id' => $tx->id, 'points' => $points, 'hotel_id' => $this->ctx->hotelId(),
            'liability_value' => $points * $program->point_value,
        ], 'membership', $m->id);

        return $tx;
    }

    private function consumeFifo(Membership $m, int $points): void
    {
        $left = $points;
        foreach (Transaction::where('loyalty_membership_id', $m->id)->where('remaining', '>', 0)->orderBy('expires_on')->orderBy('id')->lockForUpdate()->get() as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $lot->remaining);
            $lot->update(['remaining' => $lot->remaining - $take]);
            $left -= $take;
        }
    }

    private function memberNumber(): string
    {
        do {
            $n = 'M'.random_int(10_000_000, 99_999_999);
        } while (Membership::withoutGlobalScopes()->where('number', $n)->exists());

        return $n;
    }

    private function cardNumber(): string
    {
        do {
            $body = (string) random_int(100_000_000_000_000, 999_999_999_999_999);
            $n = $body.$this->luhn($body);
        } while (Card::where('card_number', $n)->exists());

        return $n;
    }

    private function luhn(string $digits): int
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $d = (int) $d;
            if ($i % 2 === 0) {
                $d *= 2;
                $d = $d > 9 ? $d - 9 : $d;
            }
            $sum += $d;
        }

        return (10 - $sum % 10) % 10;
    }
}

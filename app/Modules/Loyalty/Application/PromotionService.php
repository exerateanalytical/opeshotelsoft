<?php

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Promotion;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Promotion rules: earn multipliers by date range and outlet, birthday bonus,
 * enrolment bonus. Multipliers do not stack: the highest applicable one wins.
 */
class PromotionService
{
    public function __construct(private TenantContext $ctx) {}

    public function multiplier(string $date, ?int $outletId): float
    {
        return (float) Promotion::where('kind', 'multiplier')->where('active', true)->get()
            ->filter(fn (Promotion $p) => $p->appliesTo($date, $outletId))
            ->max('multiplier') ?: 1.0;
    }

    /** @return Collection<int, Promotion> */
    public function enrolmentBonuses(): Collection
    {
        $today = now()->toDateString();

        return Promotion::where('kind', 'enrolment')->where('active', true)->where('bonus_points', '>', 0)->get()
            ->filter(fn (Promotion $p) => $p->activeOn($today));
    }

    /**
     * Credit the birthday bonus to members whose birthday is on $date (once per year).
     * Runs per group; the GL posting goes to each member's enrolment hotel.
     */
    public function awardBirthdays(?string $date = null): int
    {
        $date = $date ?? now()->toDateString();
        $d = \Carbon\Carbon::parse($date);
        $promos = Promotion::where('kind', 'birthday')->where('active', true)->where('bonus_points', '>', 0)->get()
            ->filter(fn (Promotion $p) => $p->activeOn($date));
        if ($promos->isEmpty()) {
            return 0;
        }
        $bonus = (int) $promos->max('bonus_points');
        $promo = $promos->sortByDesc('bonus_points')->first();
        $loyalty = app(LoyaltyService::class);
        $count = 0;
        $members = Membership::with('guest')->where('status', 'active')
            ->whereHas('guest', fn ($g) => $g->whereMonth('date_of_birth', $d->month)->whereDay('date_of_birth', $d->day))->get();
        foreach ($members as $m) {
            $exists = $m->transactions()->getQuery()->where('kind', 'bonus')->where('source_type', 'birthday')->where('source_id', (string) $d->year)->exists();
            if ($exists) {
                continue;
            }
            $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
            if (! $this->ctx->hotelId() && $m->enrolled_hotel_id) {
                $this->ctx->set($m->group_id, $m->enrolled_hotel_id, [$m->enrolled_hotel_id], $saved[3]);
            }
            try {
                \Illuminate\Support\Facades\DB::transaction(fn () => $loyalty->credit($m, 'bonus', $bonus, 0, 0, 0, "Bonus anniversaire : {$promo->name}", 'birthday', $d->year));
                $count++;
            } finally {
                $this->ctx->set(...$saved);
            }
        }

        return $count;
    }
}

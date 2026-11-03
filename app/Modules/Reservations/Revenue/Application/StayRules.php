<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Revenue\Domain\RatePlanRule;
use App\Modules\Reservations\Revenue\Domain\RatePlanPackage;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;

/**
 * Length-of-stay, advance-purchase and arrival-day rules of a rate plan, plus the package
 * supplement and the fixed derived adjustment. Pure lookups used by Pricing::quote().
 */
class StayRules
{
    /** @var array<int, RatePlanRule|null> */
    private array $rules = [];

    /** @var array<int, RatePlanPackage|null> */
    private array $packages = [];

    public function rule(RatePlan $plan): ?RatePlanRule
    {
        return $this->rules[$plan->id] ??= RatePlanRule::where('rate_plan_id', $plan->id)->first();
    }

    public function package(RatePlan $plan): ?RatePlanPackage
    {
        return $this->packages[$plan->id] ??= RatePlanPackage::where('rate_plan_id', $plan->id)->where('active', true)->first();
    }

    public function forget(): void
    {
        $this->rules = $this->packages = [];
    }

    /** @throws DomainException code "restricted" */
    public function assert(RatePlan $plan, Carbon $arrival, Carbon $departure, ?Carbon $bookedOn = null): void
    {
        $rule = $this->rule($plan);
        if (! $rule) {
            return;
        }
        $nights = (int) $arrival->diffInDays($departure);
        if ($rule->min_los && $nights < $rule->min_los) {
            throw new DomainException("This rate needs a stay of at least {$rule->min_los} nights.", 'restricted');
        }
        if ($rule->max_los && $nights > $rule->max_los) {
            throw new DomainException("This rate allows a stay of at most {$rule->max_los} nights.", 'restricted');
        }
        $ahead = (int) ($bookedOn ?? now())->startOfDay()->diffInDays($arrival->copy()->startOfDay(), false);
        if ($rule->advance_min_days !== null && $ahead < $rule->advance_min_days) {
            throw new DomainException("This rate must be booked at least {$rule->advance_min_days} days before arrival.", 'restricted');
        }
        if ($rule->advance_max_days !== null && $ahead > $rule->advance_max_days) {
            throw new DomainException("This rate can be booked at most {$rule->advance_max_days} days before arrival.", 'restricted');
        }
        if (in_array($arrival->dayOfWeek, (array) $rule->closed_arrival_days, true)) {
            throw new DomainException('This rate is closed to arrival on that weekday.', 'restricted');
        }
    }

    public function derivedAmount(RatePlan $plan): int
    {
        return $plan->parent_rate_plan_id ? (int) ($this->rule($plan)?->derived_adjust_amount ?? 0) : 0;
    }

    /** Package supplement per night for the occupancy (0 when the plan has no active package). */
    public function packageNight(RatePlan $plan, int $adults, int $children): int
    {
        $p = $this->package($plan);
        if (! $p) {
            return 0;
        }
        if ($p->pricing === 'per_person') {
            return (int) ($p->adult_amount * $adults + $p->child_amount * $children);
        }

        return (int) $p->room_amount;
    }
}

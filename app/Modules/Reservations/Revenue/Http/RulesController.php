<?php

namespace App\Modules\Reservations\Revenue\Http;

use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Revenue\Application\BookingQuoter;
use App\Modules\Reservations\Revenue\Domain\RatePlanPackage;
use App\Modules\Reservations\Revenue\Domain\RatePlanRule;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;

/** Stay rules (LOS, advance purchase, arrival days, derived amount), packages and the all-in quote. */
class RulesController extends RevenueController
{
    public function __construct(private EventBus $bus) {}

    public function show(Request $request, RatePlan $ratePlan)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');

        return [
            'rate_plan' => $ratePlan->only(['id', 'code', 'name', 'parent_rate_plan_id', 'derived_adjust_percent', 'company_id', 'public']),
            'rule' => RatePlanRule::where('rate_plan_id', $ratePlan->id)->first(),
            'package' => RatePlanPackage::where('rate_plan_id', $ratePlan->id)->first(),
        ];
    }

    public function plans(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');

        return ['data' => RatePlan::orderBy('code')->get(['id', 'code', 'name', 'parent_rate_plan_id', 'derived_adjust_percent', 'company_id', 'public', 'active', 'meal_plan'])];
    }

    public function saveRule(Request $request, RatePlan $ratePlan)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'min_los' => ['nullable', 'integer', 'between:1,60'], 'max_los' => ['nullable', 'integer', 'between:1,365', $request->filled('min_los') ? 'gte:min_los' : 'nullable'],
            'advance_min_days' => ['nullable', 'integer', 'between:0,730'], 'advance_max_days' => ['nullable', 'integer', 'between:0,730', $request->filled('advance_min_days') ? 'gte:advance_min_days' : 'nullable'],
            'closed_arrival_days' => ['nullable', 'array'], 'closed_arrival_days.*' => ['integer', 'between:0,6'],
            'derived_adjust_amount' => ['nullable', 'integer', 'between:-10000000,10000000'],
        ]);
        $data['derived_adjust_amount'] = $data['derived_adjust_amount'] ?? 0;
        $rule = RatePlanRule::updateOrCreate(['rate_plan_id' => $ratePlan->id], $data);
        $this->bus->record('revenue.rules.saved', ['rate_plan_id' => $ratePlan->id] + $data, 'rate_plan', $ratePlan->id);

        return $rule;
    }

    public function savePackage(Request $request, RatePlan $ratePlan)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'inclusions' => ['nullable', 'array', 'max:20'], 'inclusions.*.name' => ['required', 'string', 'max:80'],
            'inclusions.*.code' => ['nullable', 'string', 'max:20'], 'inclusions.*.qty' => ['nullable', 'integer', 'between:1,99'],
            'pricing' => ['required', 'in:per_room,per_person'],
            'room_amount' => ['nullable', 'integer', 'min:0'], 'adult_amount' => ['nullable', 'integer', 'min:0'], 'child_amount' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $pkg = RatePlanPackage::updateOrCreate(['rate_plan_id' => $ratePlan->id], $data + ['room_amount' => 0, 'adult_amount' => 0, 'child_amount' => 0]);
        $this->bus->record('revenue.package.saved', ['rate_plan_id' => $ratePlan->id, 'name' => $pkg->name], 'rate_plan', $ratePlan->id);

        return $pkg;
    }

    public function removePackage(Request $request, RatePlan $ratePlan)
    {
        $this->need($request, 'revenue.manage');
        RatePlanPackage::where('rate_plan_id', $ratePlan->id)->delete();

        return response()->noContent();
    }

    /** Price a stay with every revenue rule (package, LOS, promo, corporate). Extension point for the reservation screens. */
    public function quote(Request $request, BookingQuoter $quoter)
    {
        $this->need($request, 'revenue.view', 'revenue.manage', 'reservations.view', 'reservations.manage');
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'], 'rate_plan_id' => ['required', 'integer'],
            'arrival' => ['required', 'date'], 'departure' => ['required', 'date', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'between:1,8'], 'children' => ['sometimes', 'integer', 'between:0,6'],
            'promo_code' => ['nullable', 'string', 'max:24'], 'corporate_code' => ['nullable', 'string', 'max:24'],
        ]);

        return $quoter->quote($data);
    }
}

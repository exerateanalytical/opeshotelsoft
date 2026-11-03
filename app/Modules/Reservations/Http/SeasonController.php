<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\RatePlanSeason;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Seasons on a rate plan: date ranges with a fixed rate or a percentage modifier, applied by Pricing. */
class SeasonController
{
    public function __construct(private EventBus $bus) {}

    public function index(Request $request, RatePlan $ratePlan)
    {
        $this->can($request, 'view');

        return ['data' => RatePlanSeason::where('rate_plan_id', $ratePlan->id)->orderBy('start_date')->get()];
    }

    public function store(Request $request, RatePlan $ratePlan)
    {
        $this->can($request, 'manage');
        $season = RatePlanSeason::create($this->validated($request) + ['rate_plan_id' => $ratePlan->id]);
        $this->bus->record('rates.season.created', $season->only(['id', 'rate_plan_id', 'name']), 'rate_plan_season', $season->id);

        return response()->json($season, 201);
    }

    public function update(Request $request, RatePlan $ratePlan, RatePlanSeason $season)
    {
        $this->can($request, 'manage');
        abort_unless($season->rate_plan_id === $ratePlan->id, 404);
        $season->update($this->validated($request));
        $this->bus->record('rates.season.updated', $season->only(['id', 'rate_plan_id', 'name']), 'rate_plan_season', $season->id);

        return $season;
    }

    public function destroy(Request $request, RatePlan $ratePlan, RatePlanSeason $season)
    {
        $this->can($request, 'manage');
        abort_unless($season->rate_plan_id === $ratePlan->id, 404);
        $season->delete();
        $this->bus->record('rates.season.deleted', ['id' => $season->id, 'rate_plan_id' => $ratePlan->id], 'rate_plan_season', $season->id);

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'room_type_id' => ['nullable', 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'modifier_percent' => ['nullable', 'numeric', 'between:-90,300', 'required_without:rate'],
            'rate' => ['nullable', 'integer', 'min:1', 'required_without:modifier_percent'],
        ]);

        return $data + ['room_type_id' => null, 'modifier_percent' => null, 'rate' => null];
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('rates.manage') || ($ability === 'view' && $u->can('rates.view')), 403);
    }
}

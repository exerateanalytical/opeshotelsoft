<?php

namespace App\Modules\Reservations\Revenue\Http;

use App\Modules\Reservations\Revenue\Application\RevenueConfig;
use App\Modules\Reservations\Revenue\Application\SuggestionService;
use App\Modules\Reservations\Revenue\Domain\CompetitorRate;
use App\Modules\Reservations\Revenue\Domain\PricingEvent;
use App\Modules\Reservations\Revenue\Domain\RateSuggestion;
use Illuminate\Http\Request;

/** Dynamic pricing: settings, local events, competitor rates and the suggestion inbox. */
class PricingController extends RevenueController
{
    public function settings(Request $request, RevenueConfig $config)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');

        return $config->get();
    }

    public function saveSettings(Request $request, RevenueConfig $config)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'high_occupancy_pct' => ['sometimes', 'integer', 'between:1,100'], 'low_occupancy_pct' => ['sometimes', 'integer', 'between:0,99'],
            'high_occupancy_uplift' => ['sometimes', 'numeric', 'between:0,100'], 'low_occupancy_discount' => ['sometimes', 'numeric', 'between:0,100'],
            'pace_threshold_pct' => ['sometimes', 'integer', 'between:1,100'], 'pace_uplift' => ['sometimes', 'numeric', 'between:0,100'],
            'weekend_uplift' => ['sometimes', 'numeric', 'between:0,100'], 'weekend_days' => ['sometimes', 'array'], 'weekend_days.*' => ['integer', 'between:0,6'],
            'competitor_undercut_pct' => ['sometimes', 'numeric', 'between:-50,50'], 'max_change_pct' => ['sometimes', 'numeric', 'between:1,100'],
            'rounding' => ['sometimes', 'integer', 'between:1,100000'],
            'upsell_discount_pct' => ['sometimes', 'array'], 'upsell_discount_pct.booking' => ['numeric', 'between:0,100'],
            'upsell_discount_pct.pre_arrival' => ['numeric', 'between:0,100'], 'upsell_discount_pct.check_in' => ['numeric', 'between:0,100'],
            'walk_policy' => ['sometimes', 'nullable', 'string', 'max:4000'], 'walk_compensation' => ['sometimes', 'integer', 'min:0'],
            'walk_partner_hotel' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);
        $s = $config->get();
        $s->update($data);

        return $s->refresh();
    }

    public function events(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');

        return ['data' => PricingEvent::orderBy('start_date')->get()];
    }

    public function saveEvent(Request $request, ?PricingEvent $event = null)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'impact_pct' => ['required', 'numeric', 'between:-50,100'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $event ? $event->update($data) : $event = PricingEvent::create($data);

        return response()->json($event->refresh(), $request->isMethod('post') ? 201 : 200);
    }

    public function removeEvent(Request $request, PricingEvent $event)
    {
        $this->need($request, 'revenue.manage');
        $event->delete();

        return response()->noContent();
    }

    public function competitors(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return ['data' => CompetitorRate::when($data['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))->orderBy('date')->orderBy('competitor')->limit(500)->get()];
    }

    public function saveCompetitor(Request $request)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'competitor' => ['required', 'string', 'max:120'], 'date' => ['required', 'date'], 'rate' => ['required', 'integer', 'min:1'],
            'room_type_id' => ['nullable', 'integer'],
        ]);
        $row = CompetitorRate::updateOrCreate(
            ['competitor' => $data['competitor'], 'date' => $data['date'], 'room_type_id' => $data['room_type_id'] ?? null],
            ['rate' => $data['rate'], 'entered_by' => $request->user()->id],
        );

        return response()->json($row, 201);
    }

    public function removeCompetitor(Request $request, CompetitorRate $competitor)
    {
        $this->need($request, 'revenue.manage');
        $competitor->delete();

        return response()->noContent();
    }

    public function suggestions(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage', 'revenue.approve');
        $status = $request->query('status', 'pending');

        return RateSuggestion::when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('date')->orderBy('room_type_id')->paginate(100);
    }

    public function generate(Request $request, SuggestionService $svc)
    {
        $this->need($request, 'revenue.manage', 'revenue.approve');
        $data = $request->validate([
            'from' => ['required', 'date', 'after_or_equal:today'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'rate_plan_id' => ['nullable', 'integer'], 'room_type_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $svc->generate($data['from'], $data['to'], $data['rate_plan_id'] ?? null, $data['room_type_id'] ?? null)->values()], 201);
    }

    public function accept(Request $request, RateSuggestion $suggestion, SuggestionService $svc)
    {
        $this->need($request, 'revenue.approve');
        $data = $request->validate(['rate' => ['nullable', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:250']]);

        return $svc->accept($suggestion, $request->user(), $data['rate'] ?? null, $data['note'] ?? null);
    }

    public function reject(Request $request, RateSuggestion $suggestion, SuggestionService $svc)
    {
        $this->need($request, 'revenue.approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:250']]);

        return $svc->reject($suggestion, $request->user(), $data['note'] ?? null);
    }
}

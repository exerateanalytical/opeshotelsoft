<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Guests\Domain\Company;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;

/** Travel agents are companies of kind travel_agent with a commission % and IATA number. */
class TravelAgentController
{
    public function __construct(private EventBus $bus) {}

    public function index(Request $request)
    {
        $this->can($request, 'view');

        return Company::where('kind', 'travel_agent')
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$t%")->orWhere('iata_number', 'ilike', "%$t%")))
            ->orderBy('name')->paginate(100);
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $agent = Company::create($this->validated($request) + ['kind' => 'travel_agent']);
        $this->bus->record('reservation.travel_agent.created', $agent->only(['id', 'name', 'iata_number']), 'company', $agent->id);

        return response()->json($agent, 201);
    }

    public function update(Request $request, Company $agent)
    {
        $this->can($request, 'manage');
        abort_unless($agent->kind === 'travel_agent', 404);
        $agent->update($this->validated($request, true));

        return $agent;
    }

    /** GET travel-agents/commissions?from&to — commission due per agent on stays arriving in the range. */
    public function commissions(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'agent_id' => ['nullable', 'integer']]);
        $agents = Company::where('kind', 'travel_agent')->get()->keyBy('id');

        $rows = Reservation::with('guest:id,first_name,last_name')->whereNotNull('agent_id')
            ->whereIn('agent_id', $agents->keys())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereBetween('arrival', [$data['from'], $data['to']])
            ->when($data['agent_id'] ?? null, fn ($q, $id) => $q->where('agent_id', $id))
            ->orderBy('arrival')->get();

        $report = $rows->groupBy('agent_id')->map(function ($list, $agentId) use ($agents) {
            $agent = $agents[$agentId];
            $pct = (float) $agent->commission_percent;
            $lines = $list->map(fn (Reservation $r) => [
                'reservation_id' => $r->id, 'number' => $r->number, 'status' => $r->status,
                'guest' => trim(($r->guest->first_name ?? '').' '.($r->guest->last_name ?? '')),
                'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(),
                'revenue' => (int) $r->total_amount, 'commission' => (int) round($r->total_amount * $pct / 100),
            ])->values();

            return [
                'agent_id' => (int) $agentId, 'name' => $agent->name, 'iata_number' => $agent->iata_number, 'commission_percent' => $pct,
                'bookings' => $lines->count(), 'revenue' => $lines->sum('revenue'), 'commission' => $lines->sum('commission'), 'lines' => $lines,
            ];
        })->values();

        return ['from' => $data['from'], 'to' => $data['to'], 'data' => $report, 'total_commission' => $report->sum('commission')];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:150'],
            'iata_number' => ['nullable', 'string', 'max:20'],
            'commission_percent' => [$req, 'numeric', 'between:0,50'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'country' => ['nullable', 'string', 'size:2'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('reservations.manage') || ($ability === 'view' && $u->can('reservations.view')), 403);
    }
}

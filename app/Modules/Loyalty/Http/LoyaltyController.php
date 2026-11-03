<?php

namespace App\Modules\Loyalty\Http;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Program;
use App\Modules\Loyalty\Domain\Tier;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoyaltyController
{
    public function __construct(private LoyaltyService $loyalty) {}

    public function program(Request $request)
    {
        $this->can($request, 'view');

        return Program::with('tiers')->first();
    }

    public function setup(Request $request)
    {
        abort_unless($request->user()->can('loyalty.manage') && $request->user()->all_hotels, 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'], 'spend_per_point' => ['sometimes', 'integer', 'min:1'],
            'point_value' => ['sometimes', 'integer', 'min:1'], 'expiry_months' => ['sometimes', 'integer', 'between:1,120'],
            'enrolment_bonus' => ['sometimes', 'integer', 'min:0'], 'eligible_departments' => ['sometimes', 'array'],
        ]);
        $program = $this->loyalty->setupDefault($data['name'] ?? 'Membership Rewards');
        $program->update(collect($data)->except('name')->all());

        return $program->fresh('tiers');
    }

    public function members(Request $request)
    {
        $this->can($request, 'view');

        return Membership::with('guest:id,first_name,last_name,email,phone', 'tier:id,code,name')
            ->when($request->query('tier'), fn ($q, $v) => $q->whereHas('tier', fn ($t) => $t->where('code', $v)))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'ilike', "%$v%")
                ->orWhereHas('guest', fn ($g) => $g->where('last_name', 'ilike', "%$v%")->orWhere('first_name', 'ilike', "%$v%"))))
            ->orderByDesc('id')->paginate(50);
    }

    public function show(Request $request, Membership $member)
    {
        $this->can($request, 'view');

        return $member->load('guest', 'tier', 'cards')->setRelation('transactions', $member->transactions()->limit(100)->get());
    }

    public function enrol(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['guest_id' => ['required', 'integer'], 'card' => ['sometimes', 'in:digital,physical']]);

        return response()->json($this->loyalty->enrol(Guest::findOrFail($data['guest_id']), $data['card'] ?? 'digital'), 201);
    }

    public function issueCard(Request $request, Membership $member)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['kind' => ['sometimes', 'in:digital,physical']]);

        return response()->json($this->loyalty->issueCard($member, $data['kind'] ?? 'physical'), 201);
    }

    /** Scan a card (QR token or number) at the desk or POS. */
    public function lookup(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['card' => ['required', 'string', 'max:64']]);
        $m = $this->loyalty->findByCard($data['card']);

        return $m->only(['id', 'number', 'points_balance', 'status']) + [
            'guest' => $m->guest->only(['id', 'full_name', 'vip']),
            'tier' => $m->tier->only(['code', 'name', 'benefits']),
        ];
    }

    public function redeem(Request $request, Membership $member, FolioService $folios)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['folio_id' => ['required', 'integer'], 'points' => ['required', 'integer', 'min:1']]);

        return response()->json($this->loyalty->redeemOnFolio($member, Folio::findOrFail($data['folio_id']), $data['points'], $folios), 201);
    }

    public function adjust(Request $request, Membership $member)
    {
        abort_unless($request->user()->can('loyalty.adjust_points'), 403);
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0'], 'reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->loyalty->adjust($member, $data['points'], $data['reason']), 201);
    }

    public function tiers(Request $request)
    {
        $this->can($request, 'view');
        $program = Program::first();

        return $program ? $program->tiers()->get() : [];
    }

    public function storeTier(Request $request)
    {
        abort_unless($request->user()->can('loyalty.manage') && $request->user()->all_hotels, 403);
        $program = Program::first() ?? throw new DomainException('Set up the loyalty programme first.', 'loyalty-no-program');
        $data = $request->validate($this->tierRules(true));
        $data['code'] = strtoupper($data['code']);
        if ($program->tiers()->where('code', $data['code'])->exists()) {
            throw new DomainException('A tier with this code already exists.', 'loyalty-tier-duplicate');
        }

        return DB::transaction(function () use ($program, $data) {
            $tier = $program->tiers()->create($data + ['benefits' => [], 'active' => true]);
            $this->assertIncreasing($program);

            return response()->json($tier->fresh(), 201);
        });
    }

    public function updateTier(Request $request, Tier $tier)
    {
        abort_unless($request->user()->can('loyalty.manage') && $request->user()->all_hotels, 403);
        $program = Program::findOrFail($tier->loyalty_program_id); // group-scoped: 404 for another group's tier
        $data = $request->validate($this->tierRules(false));
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
            if (Tier::where('loyalty_program_id', $tier->loyalty_program_id)->where('code', $data['code'])->whereKeyNot($tier->id)->exists()) {
                throw new DomainException('A tier with this code already exists.', 'loyalty-tier-duplicate');
            }
        }
        if (($data['active'] ?? true) === false && $tier->active && Membership::where('loyalty_tier_id', $tier->id)->exists()) {
            throw new DomainException('This tier still has members and cannot be deactivated.', 'loyalty-tier-has-members');
        }

        return DB::transaction(function () use ($tier, $data, $program) {
            $tier->update($data);
            $this->assertIncreasing($program);

            return $tier->fresh();
        });
    }

    private function tierRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => [$req, 'string', 'max:100'],
            'min_qualifying_points' => [$req, 'integer', 'min:0'], 'min_qualifying_nights' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'earn_multiplier' => ['sometimes', 'numeric', 'between:0,99.99'], 'benefits' => ['sometimes', 'array'], 'benefits.*' => ['string', 'max:64'],
            'rank' => [$req, 'integer', 'between:0,255'], 'active' => ['sometimes', 'boolean'],
        ];
    }

    /** Active tiers ordered by rank must have strictly increasing points thresholds (and unique ranks). */
    private function assertIncreasing(Program $program): void
    {
        $prev = null;
        foreach ($program->tiers()->where('active', true)->orderBy('rank')->get() as $t) {
            if ($prev && ($t->rank === $prev->rank || $t->min_qualifying_points <= $prev->min_qualifying_points)) {
                throw new DomainException("Tier thresholds must strictly increase with sort order ({$prev->code} → {$t->code}).", 'loyalty-tier-thresholds');
            }
            $prev = $t;
        }
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('loyalty.manage') || ($ability === 'view' && ($u->can('loyalty.view') || $u->can('pos.manage') || $u->can('frontdesk.manage'))), 403);
    }
}

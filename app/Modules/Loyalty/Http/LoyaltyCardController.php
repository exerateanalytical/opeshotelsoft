<?php

namespace App\Modules\Loyalty\Http;

use App\Modules\Loyalty\Application\CardService;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Application\PosLoyalty;
use App\Modules\Loyalty\Application\StatementService;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Promotion;
use App\Modules\POS\Domain\Check;
use Illuminate\Http\Request;

/** Cards (show, QR, PDF, block, replace), promotions, statements and POS member identification. */
class LoyaltyCardController
{
    public function __construct(private CardService $cards, private LoyaltyService $loyalty) {}

    public function card(Request $request, int $card)
    {
        $this->view($request);
        $c = $this->cards->find($card);
        $m = Membership::with('guest:id,first_name,last_name,email', 'tier:id,code,name,benefits')->findOrFail($c->loyalty_membership_id);

        return $c->toArray() + ['usable' => $c->isUsable(), 'qr_svg' => $this->cards->qrSvg($c),
            'member' => $m->only(['id', 'number', 'points_balance', 'status']) + ['guest' => $m->guest, 'tier' => $m->tier]];
    }

    public function cardPdf(Request $request, int $card)
    {
        $this->view($request);

        return $this->pdfResponse($this->cards->pdf($this->cards->find($card), $this->lang($request)), 'loyalty-card-'.$card);
    }

    public function block(Request $request, int $card)
    {
        $this->cardsAbility($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->cards->block($this->cards->find($card), $data['reason']);
    }

    public function unblock(Request $request, int $card)
    {
        $this->cardsAbility($request);

        return $this->cards->unblock($this->cards->find($card));
    }

    public function replace(Request $request, int $card)
    {
        $this->cardsAbility($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->cards->replace($this->cards->find($card), $data['reason'] ?? null), 201);
    }

    public function promotions(Request $request)
    {
        $this->view($request);

        return Promotion::orderByDesc('active')->orderByDesc('id')->get();
    }

    public function storePromotion(Request $request)
    {
        $this->promoAbility($request);

        return response()->json(Promotion::create($request->validate($this->promoRules(true))), 201);
    }

    public function updatePromotion(Request $request, Promotion $promotion)
    {
        $this->promoAbility($request);
        $promotion->update($request->validate($this->promoRules(false)));

        return $promotion->fresh();
    }

    public function statement(Request $request, Membership $member, StatementService $statements)
    {
        $this->view($request);
        $p = $this->period($request);

        return $statements->data($member, $p['from'] ?? null, $p['to'] ?? null);
    }

    public function statementPdf(Request $request, Membership $member, StatementService $statements)
    {
        $this->view($request);
        $p = $this->period($request);

        return $this->pdfResponse($statements->pdf($member, $p['from'] ?? null, $p['to'] ?? null, $this->lang($request, $member)), 'statement-'.$member->number);
    }

    public function emailStatement(Request $request, Membership $member, StatementService $statements)
    {
        abort_unless($request->user()->can('loyalty.manage'), 403);
        $p = $this->period($request);
        $sent = $statements->email($member, $p['from'] ?? null, $p['to'] ?? null);

        return response()->json(['queued' => count($sent), 'messages' => collect($sent)->map->only(['id', 'channel', 'recipient', 'status'])->values()], 202);
    }

    /** POS: identify the member on an open check by card scan or member id (from the member search). */
    public function attachToCheck(Request $request, Check $check, PosLoyalty $pos)
    {
        abort_unless($request->user()->can('pos.manage') || $request->user()->can('loyalty.manage'), 403);
        $data = $request->validate([
            'card' => ['required_without:member_id', 'nullable', 'string', 'max:64'],
            'member_id' => ['required_without:card', 'nullable', 'integer'],
        ]);
        $m = ! empty($data['card']) ? $this->loyalty->findByCard($data['card']) : Membership::with('guest', 'tier')->findOrFail($data['member_id']);
        $pos->attach($check, $m);
        $value = $this->loyalty->program()->point_value;

        return ['check_id' => $check->id, 'member' => $m->only(['id', 'number', 'points_balance', 'status']) + [
            'guest' => $m->guest?->only(['id', 'first_name', 'last_name']), 'tier' => $m->tier?->only(['code', 'name']),
            'points_value' => $m->points_balance * $value, 'point_value' => $value,
        ]];
    }

    private function promoRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:100'], 'kind' => [$req, 'in:multiplier,birthday,enrolment'],
            'multiplier' => ['sometimes', 'numeric', 'between:1,10'], 'bonus_points' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'outlet_ids' => ['nullable', 'array'], 'outlet_ids.*' => ['integer'], 'active' => ['sometimes', 'boolean'],
        ];
    }

    private function period(Request $request): array
    {
        return $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
    }

    private function lang(Request $request, ?Membership $m = null): string
    {
        $l = $request->query('lang') ?? $m?->guest?->language ?? $request->user()?->locale ?? 'fr';

        return str_starts_with(strtolower((string) $l), 'en') ? 'en' : 'fr';
    }

    private function pdfResponse(string $pdf, string $name)
    {
        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^\w.-]/', '_', $name).'.pdf"']);
    }

    private function view(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->can('loyalty.view') || $u->can('loyalty.manage') || $u->can('pos.manage') || $u->can('frontdesk.manage'), 403);
    }

    private function cardsAbility(Request $request): void
    {
        abort_unless($request->user()->can('loyalty.cards') || $request->user()->can('loyalty.manage'), 403);
    }

    private function promoAbility(Request $request): void
    {
        abort_unless($request->user()->can('loyalty.promotions'), 403);
    }
}

<?php

namespace App\Modules\MemberPortal\Application;

use App\Modules\Loyalty\Application\CardService;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Application\StatementService;
use App\Modules\Loyalty\Domain\Card;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Program;
use App\Modules\Loyalty\Domain\Transaction;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read models for the member's home, loyalty card, points and the directory of hotels where the card is accepted.
 * Everything is derived from the Loyalty module (card, QR, balance, statement) for the member's own membership.
 */
class MemberPortalService
{
    public function __construct(private LoyaltyService $loyalty, private CardService $cards, private StatementService $statements, private MemberBookings $bookings) {}

    public function program(): ?Program
    {
        return Program::with('tiers')->where('active', true)->first();
    }

    public function home(MemberAccount $a): array
    {
        $m = $a->membership();
        $guest = $a->guest;
        $stays = Reservation::with('roomType:id,name', 'ratePlan:id,name', 'room:id,number')->where('guest_id', $a->guest_id)
            ->whereIn('status', ['tentative', 'confirmed', 'checked_in'])->whereDate('departure', '>=', now()->toDateString())->orderBy('arrival')->get();
        $hotels = Hotel::whereIn('id', $stays->pluck('hotel_id')->unique())->get()->keyBy('id');
        $current = $stays->firstWhere('status', 'checked_in');
        $next = $stays->first(fn ($r) => $r->status !== 'checked_in');
        $program = $this->program();

        return [
            'name' => $guest?->first_name, 'full_name' => trim("{$guest?->first_name} {$guest?->last_name}"),
            'member' => $m ? $this->membershipData($m, $program) : null,
            'current_stay' => $current ? $this->bookings->summary($current, $hotels[$current->hotel_id] ?? null) : null,
            'next_stay' => $next ? $this->bookings->summary($next, $hotels[$next->hotel_id] ?? null) : null,
            'can_join' => ! $m && (bool) $program,
        ];
    }

    private function membershipData(Membership $m, ?Program $program): array
    {
        $m->loadMissing('tier');
        $pointValue = $program?->point_value ?? 0;
        $next = $program?->tiers->where('rank', '>', $m->tier->rank)->sortBy('rank')->first();
        $qp = (int) Transaction::where('loyalty_membership_id', $m->id)->where('kind', 'earn')->where('created_at', '>=', now()->subYear())->sum('qualifying_points');

        return [
            'number' => $m->number, 'status' => $m->status, 'points' => (int) $m->points_balance, 'points_value' => (int) $m->points_balance * $pointValue,
            'tier' => ['code' => $m->tier->code, 'name' => $m->tier->name, 'benefits' => $m->tier->benefits ?? [], 'earn_multiplier' => $m->tier->earn_multiplier],
            'next_tier' => $next ? ['name' => $next->name, 'points_needed' => max(0, (int) $next->min_qualifying_points - $qp)] : null,
            'lifetime_points' => (int) $m->lifetime_points, 'lifetime_nights' => (int) $m->lifetime_nights,
        ];
    }

    // ------------------------------------------------------------------ card

    public function card(MemberAccount $a): array
    {
        $m = $a->membership();
        if (! $m) {
            return ['member' => null, 'card' => null, 'can_join' => (bool) $this->program()];
        }
        $program = $this->program();
        $card = $this->currentCard($m);

        return [
            'member' => $this->membershipData($m, $program) + ['name' => trim("{$a->guest?->first_name} {$a->guest?->last_name}")],
            'card' => $card ? [
                'id' => $card->id, 'card_number' => $card->card_number, 'kind' => $card->kind, 'status' => $card->status, 'usable' => $card->isUsable(),
                'expires_on' => $card->expires_on?->toDateString(), 'qr_svg' => $this->cards->qrSvg($card),
            ] : null,
            'can_join' => false,
        ];
    }

    private function currentCard(Membership $m): ?Card
    {
        return $m->cards()->orderByRaw("status = 'active' desc")->orderByDesc('id')->first();
    }

    /** Enrol the member's profile in the group's loyalty programme. */
    public function join(MemberAccount $a): array
    {
        $this->loyalty->enrol($a->guest, 'digital');

        return $this->card($a);
    }

    /** A card of the member's own membership (404 for any other card). */
    private function ownCard(MemberAccount $a, int $cardId): Card
    {
        $m = $a->membership() ?? throw new DomainException('You have no loyalty membership.', 'no-membership');
        $card = Card::where('loyalty_membership_id', $m->id)->findOrFail($cardId);

        return $card;
    }

    public function blockCard(MemberAccount $a, int $cardId): array
    {
        $this->cards->block($this->ownCard($a, $cardId), 'Blocked by the member in the portal');

        return $this->card($a);
    }

    public function replaceCard(MemberAccount $a, int $cardId): array
    {
        $this->cards->replace($this->ownCard($a, $cardId), 'Replacement requested by the member in the portal');

        return $this->card($a);
    }

    public function cardPdf(MemberAccount $a, int $cardId, string $lang): Response
    {
        $pdf = $this->cards->pdf($this->ownCard($a, $cardId), $lang);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="loyalty-card.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    // ------------------------------------------------------------------ points

    public function points(MemberAccount $a, ?string $from, ?string $to): array
    {
        $m = $a->membership();
        if (! $m) {
            return ['member' => null];
        }
        $program = $this->program();
        $s = $this->statements->data($m, $from ?? now()->subYear()->toDateString(), $to);
        $soon = Transaction::where('loyalty_membership_id', $m->id)->where('remaining', '>', 0)->whereBetween('expires_on', [now()->toDateString(), now()->addDays(90)->toDateString()])
            ->orderBy('expires_on')->get(['expires_on', 'remaining']);

        return [
            'member' => $this->membershipData($m, $program),
            'from' => $s['from'], 'to' => $s['to'], 'opening' => $s['opening'], 'earned' => $s['earned'], 'spent' => $s['spent'], 'closing' => $s['closing'],
            'lines' => array_reverse($s['lines']),
            'expiring' => (int) $s['expiring'], 'expiring_on' => $soon->first()?->expires_on?->toDateString(),
            'earn' => $program ? [
                'spend_per_point' => (int) $program->spend_per_point, 'point_value' => (int) $program->point_value, 'expiry_months' => (int) $program->expiry_months,
                'multiplier' => $m->tier?->earn_multiplier, 'currency' => 'XAF',
            ] : null,
        ];
    }

    public function statementPdf(MemberAccount $a, ?string $from, ?string $to, string $lang): Response
    {
        $m = $a->membership() ?? throw new DomainException('You have no loyalty membership.', 'no-membership');
        $pdf = $this->statements->pdf($m, $from ?? now()->subYear()->toDateString(), $to, $lang);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="points-statement.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    // ------------------------------------------------------------------ hotels where the card is accepted

    /** Every active hotel of the programme's group (the card works group-wide), with what members get there. */
    public function hotels(MemberAccount $a): array
    {
        $m = $a->membership();
        $program = $this->program();
        $perks = $m?->tier ? ($m->tier->benefits ?? []) : [];

        return [
            'accepted' => (bool) $program,
            'earn' => $program ? ['spend_per_point' => (int) $program->spend_per_point, 'point_value' => (int) $program->point_value] : null,
            'perks' => $perks,
            'hotels' => Hotel::where('status', 'active')->orderBy('name')->get()->map(fn (Hotel $h) => [
                'id' => $h->id, 'code' => $h->code, 'name' => $h->name, 'city' => $h->city, 'country' => $h->country, 'address' => $h->address, 'phone' => $h->phone,
                'stars' => $h->stars, 'currency' => $h->currency, 'description' => $h->settings['description'] ?? null,
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------ profile

    public function profile(MemberAccount $a): array
    {
        $g = $a->guest;

        return [
            'first_name' => $g?->first_name, 'last_name' => $g?->last_name, 'email' => $a->email ?? $g?->email, 'phone' => $a->phone ?? $g?->phone,
            'verified_contact' => $a->verified_contact, 'locale' => $a->locale, 'marketing_consent' => (bool) $g?->marketing_consent,
            'member_number' => $a->membership()?->number,
        ];
    }

    public function updateProfile(MemberAccount $a, array $d): array
    {
        if (isset($d['locale'])) {
            $a->update(['locale' => $d['locale']]);
            $a->guest?->update(['language' => $d['locale']]);
        }
        if (array_key_exists('marketing_consent', $d) && $a->guest) {
            $a->guest->update(['marketing_consent' => (bool) $d['marketing_consent'], 'consent_at' => now()]);
        }

        return $this->profile($a->refresh());
    }
}

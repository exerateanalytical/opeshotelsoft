<?php

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Card;
use App\Modules\Loyalty\Domain\Membership;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Modules\Documents\Application\DocumentRenderer;
use Illuminate\Support\Facades\DB;

/** Card life cycle (block, replace, expire) and the printable card. */
class CardService
{
    public function __construct(private EventBus $bus, private LoyaltyService $loyalty) {}

    /** Card of a membership visible in the current group (404 otherwise). */
    public function find(int $cardId): Card
    {
        $card = Card::findOrFail($cardId);
        Membership::findOrFail($card->loyalty_membership_id);

        return $card;
    }

    public function block(Card $card, string $reason): Card
    {
        if ($card->status !== 'active') {
            throw new DomainException('Only an active card can be blocked.', 'card-not-active');
        }
        $card->update(['status' => 'blocked', 'blocked_at' => now(), 'block_reason' => $reason]);
        $this->bus->record('loyalty.card_blocked', ['card_id' => $card->id, 'membership_id' => $card->loyalty_membership_id, 'reason' => $reason], 'membership', $card->loyalty_membership_id);

        return $card->fresh();
    }

    public function unblock(Card $card): Card
    {
        if ($card->status !== 'blocked') {
            throw new DomainException('This card is not blocked.', 'card-not-blocked');
        }
        $card->update(['status' => 'active', 'blocked_at' => null, 'block_reason' => null]);

        return $card->fresh();
    }

    /** Issue a new card of the same kind; the old one becomes "replaced" (a blocked card stays blocked). */
    public function replace(Card $card, ?string $reason = null): Card
    {
        if ($card->status === 'replaced' || $card->replaced_by_card_id) {
            throw new DomainException('This card was already replaced.', 'card-already-replaced');
        }

        return DB::transaction(function () use ($card, $reason) {
            $m = Membership::findOrFail($card->loyalty_membership_id);
            $new = $this->loyalty->issueCard($m, $card->kind);
            $card->refresh();
            $card->update(['status' => $card->status === 'blocked' ? 'blocked' : 'replaced', 'replaced_by_card_id' => $new->id,
                'block_reason' => $card->block_reason ?? $reason]);
            $this->bus->record('loyalty.card_replaced', ['card_id' => $card->id, 'new_card_id' => $new->id, 'membership_id' => $m->id], 'membership', $m->id);

            return $new;
        });
    }

    /** Mark cards past their expiry date as expired (all groups; run daily). */
    public function expireCards(?string $date = null): int
    {
        return Card::where('status', 'active')->whereDate('expires_on', '<', $date ?? now()->toDateString())->update(['status' => 'expired']);
    }

    public function qrSvg(Card $card): string
    {
        return QrCode::svg($card->token, 4);
    }

    public function pdf(Card $card, string $lang): string
    {
        $m = Membership::with('guest', 'tier')->findOrFail($card->loyalty_membership_id);

        // ID-1 card, 85.6 x 54 mm, through the document engine (verify QR + number in the corner).
        return app(DocumentRenderer::class)->render('loyalty_card', ['card' => $card, 'member' => $m, 'qr' => QrCode::matrix($card->token)], [
            'view' => 'loyalty::card', 'size' => 'id1', 'lang' => $lang, 'number' => $card->card_number, 'source_type' => 'loyalty_card',
            'source_id' => $card->id, 'title' => $lang === 'en' ? 'Loyalty card' : 'Carte de fidélité',
        ])->pdf;
    }
}

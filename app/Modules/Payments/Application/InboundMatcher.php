<?php

namespace App\Modules\Payments\Application;

use App\Models\User;
use App\Modules\Accounting\Application\Reconciliation;
use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\BankStatementLine;
use App\Modules\Payments\Domain\PaymentInbound;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\Payments\Domain\PaymentLink;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automatic matching of money received outside a started intent (unsolicited
 * MoMo callbacks, imported MoMo / bank statement credit lines) to open pay links.
 *
 * Score (0-100): pay-link code in the message +65, amount equal to the link balance +25
 * (or +10 for a partial amount on a link that allows it), payer phone matches +15,
 * date outside the link's validity -20. Auto-posted when the best score >= AUTO_POST,
 * the amount fits the balance and no other candidate is within MARGIN; otherwise review.
 */
class InboundMatcher
{
    public const AUTO_POST = 90;

    public const MARGIN = 15;

    public function __construct(private PaymentService $payments, private PayLinkService $links, private EventBus $bus, private TenantContext $ctx) {}

    /** @param array{transaction_id:string, amount:int, payer_phone?:?string, message?:?string, date?:?string} $data */
    public function receiveCallback(string $provider, array $data): PaymentInbound
    {
        $existing = PaymentInbound::where('source', 'callback')->where('transaction_id', $data['transaction_id'])->first();
        if ($existing) {
            return $existing;
        }
        $inbound = PaymentInbound::create([
            'source' => 'callback', 'status' => 'review', 'provider' => $provider, 'transaction_id' => $data['transaction_id'], 'amount' => (int) $data['amount'],
            'payer_phone' => $data['payer_phone'] ?? null, 'message' => isset($data['message']) ? mb_substr($data['message'], 0, 250) : null,
            'date' => Carbon::parse($data['date'] ?? now())->toDateString(),
        ]);

        return $this->match($inbound);
    }

    /** Pull unmatched credit lines of a bank / MoMo account into the matcher. Returns counts. */
    public function scanStatement(BankAccount $bank): array
    {
        $lines = BankStatementLine::where('bank_account_id', $bank->id)->whereNull('journal_line_id')->where('amount', '>', 0)
            ->whereNotIn('id', PaymentInbound::whereNotNull('bank_statement_line_id')->select('bank_statement_line_id'))->orderBy('id')->get();
        $out = ['scanned' => 0, 'posted' => 0, 'review' => 0];
        foreach ($lines as $s) {
            $inbound = PaymentInbound::create([
                'source' => 'statement', 'status' => 'review', 'provider' => $bank->kind === 'mobile_money' ? 'mtn_momo' : 'bank',
                'transaction_id' => 'stl-'.$s->id, 'bank_statement_line_id' => $s->id, 'amount' => (int) $s->amount,
                'message' => mb_substr(trim($s->reference.' '.$s->description), 0, 250), 'date' => $s->date->toDateString(),
                'payer_phone' => self::phoneIn((string) $s->description.' '.$s->reference),
            ]);
            $inbound = $this->match($inbound);
            $out['scanned']++;
            $out[$inbound->status === 'posted' ? 'posted' : 'review']++;
        }
        if ($out['posted'] > 0) {
            app(Reconciliation::class)->autoMatch($bank);
        }

        return $out;
    }

    /** @return array<int, array{link_id:int, code:string, score:int, reasons:string[]}> best first */
    public function candidates(PaymentInbound $in): array
    {
        $message = mb_strtoupper((string) $in->message);
        $phone = self::normalise($in->payer_phone);
        $out = [];
        foreach (PaymentLink::whereIn('status', ['open', 'partially_paid'])->with('intents:id,payment_link_id,payer_phone')->get() as $link) {
            $score = 0;
            $reasons = [];
            if ($message !== '' && str_contains($message, $link->code)) {
                $score += 65;
                $reasons[] = 'reference';
            }
            $balance = $link->balance();
            if ($in->amount === $balance) {
                $score += 25;
                $reasons[] = 'amount';
            } elseif ($in->amount < $balance && $link->allow_partial) {
                $score += 10;
                $reasons[] = 'partial_amount';
            } elseif ($in->amount > $balance) {
                $reasons[] = 'amount_exceeds';
            }
            $phones = collect([$link->payer_phone])->merge($link->intents->pluck('payer_phone'))->map(fn ($p) => self::normalise($p))->filter();
            if ($phone && $phones->contains($phone)) {
                $score += 15;
                $reasons[] = 'phone';
            }
            if ($in->date->lt($link->created_at->copy()->startOfDay()) || ($link->expires_at && $in->date->gt($link->expires_at->copy()->endOfDay()))) {
                $score -= 20;
                $reasons[] = 'date_outside';
            }
            if ($score >= 25) {
                $out[] = ['link_id' => $link->id, 'code' => $link->code, 'label' => $link->label, 'balance' => $balance, 'score' => min(100, max(0, $score)), 'reasons' => $reasons];
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, 5);
    }

    public function match(PaymentInbound $in): PaymentInbound
    {
        $c = $this->candidates($in);
        $best = $c[0] ?? null;
        $in->update(['candidates' => $c, 'confidence' => $best['score'] ?? 0, 'payment_link_id' => $best['link_id'] ?? null]);
        $certain = $best && $best['score'] >= self::AUTO_POST && ! in_array('amount_exceeds', $best['reasons'], true)
            && (! isset($c[1]) || $c[1]['score'] <= $best['score'] - self::MARGIN);

        return $certain ? $this->post($in, PaymentLink::findOrFail($best['link_id'])) : $in->fresh();
    }

    /** Post the inbound money to a link (auto or from the review queue). */
    public function post(PaymentInbound $in, PaymentLink $link, ?User $actor = null): PaymentInbound
    {
        if ($in->status !== 'review') {
            throw new DomainException('This receipt is already '.$in->status.'.');
        }
        if (! in_array($link->status, ['open', 'partially_paid'], true) || $in->amount > $link->balance()) {
            throw new DomainException('The amount exceeds the balance of this link.');
        }
        $method = match ($in->provider) {
            'orange_money' => 'OM', 'bank' => 'XFER', default => 'MTN',
        };
        $intent = DB::transaction(function () use ($in, $link, $actor, $method) {
            $intent = PaymentIntent::create([
                'reference' => (string) Str::uuid(), 'provider' => $in->provider === 'bank' ? 'bank_transfer' : $in->provider, 'driver' => 'matched',
                'amount' => $in->amount, 'currency' => $link->currency, 'payment_link_id' => $link->id,
                'folio_id' => $link->target_type === 'folio' ? $link->target_id : null,
                'reservation_id' => $link->target_type === 'reservation' ? $link->target_id : null,
                'payer_phone' => $in->payer_phone, 'channel' => $in->source, 'created_by' => $actor?->id,
                'provider_reference' => $in->transaction_id, 'meta' => ['method' => $method, 'inbound_id' => $in->id],
            ]);
            $intent = $this->payments->settle($intent, 'succeeded');
            $in->update(['status' => 'posted', 'payment_link_id' => $link->id, 'payment_intent_id' => $intent->id, 'decided_by' => $actor?->id]);

            return $intent;
        });
        $this->bus->record('payment.inbound_posted', ['inbound_id' => $in->id, 'link_id' => $link->id, 'intent_id' => $intent->id, 'amount' => $in->amount, 'confidence' => $in->confidence], 'payment_inbound', $in->id);

        return $in->fresh();
    }

    public function ignore(PaymentInbound $in, ?User $actor = null): PaymentInbound
    {
        if ($in->status !== 'review') {
            throw new DomainException('This receipt is already '.$in->status.'.');
        }
        $in->update(['status' => 'ignored', 'decided_by' => $actor?->id]);

        return $in;
    }

    public static function normalise(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) >= 9 ? substr($d, -9) : null;
    }

    private static function phoneIn(string $text): ?string
    {
        return preg_match('/(?:\+?237)?\s?(6\d{8})/', $text, $m) ? $m[1] : null;
    }
}

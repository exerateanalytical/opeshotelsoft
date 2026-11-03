<?php

namespace App\Modules\Payments\Application;

use App\Models\User;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\Payments\Domain\PaymentLink;
use App\Modules\POS\Application\PosService;
use App\Modules\POS\Domain\Check;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Support\QrCode;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pay-by-QR / link. A link targets one document (folio, POS check, city-ledger
 * invoice or booking deposit); the guest page starts PaymentIntents against it
 * (MTN, Orange, card; partial payments allowed unless disabled). When an intent
 * succeeds PaymentService::settle() calls apply(), which posts to the document:
 * - folio / reservation: folio payment line (GL via folio.line_posted: Dr treasury / Cr 411).
 * - invoice: payment on the company's city-ledger folio + invoice paid (Dr treasury / Cr 411200).
 * - POS check: Dr treasury / Cr 419100 customer advances; once fully paid the check is settled
 *   with the PAYLINK tender (account 419100), so pos.check_settled clears 419 against revenue and VAT.
 */
class PayLinkService
{
    public const POS_TENDER = 'PAYLINK';

    public function __construct(private PaymentService $payments, private FolioService $folios, private Ledger $ledger, private EventBus $bus, private TenantContext $ctx) {}

    /** Amount still owed on the target document. */
    public function due(string $type, int $id): int
    {
        return match ($type) {
            'folio' => max(0, (int) Folio::findOrFail($id)->balance),
            'pos_check' => (int) Check::findOrFail($id)->total,
            'invoice' => (function () use ($id) {
                $i = Invoice::findOrFail($id);
                $i->company_id ?: throw new DomainException('Only company (city-ledger) invoices can be paid by link.');

                return max(0, (int) $i->total - (int) $i->paid);
            })(),
            'reservation' => (function () use ($id) {
                $r = Reservation::findOrFail($id);
                $deposit = max(0, (int) $r->deposit_required - (int) $r->deposit_paid);

                return $deposit ?: max(0, (int) $r->total_amount - (int) $r->deposit_paid);
            })(),
            default => throw new DomainException('Unknown pay link target.'),
        };
    }

    public function create(string $type, int $id, ?int $amount = null, array $opts = [], ?User $actor = null): PaymentLink
    {
        if (! in_array($type, PaymentLink::TARGETS, true)) {
            throw new DomainException('Unknown pay link target.');
        }
        $label = $this->assertPayable($type, $id);
        $due = $this->due($type, $id);
        $amount ??= $due;
        if ($amount <= 0 || $due <= 0) {
            throw new DomainException('Nothing is due on this document.', 'nothing-due');
        }
        if ($amount > $due) {
            throw new DomainException('The link amount exceeds the balance due.');
        }
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        do {
            $code = Str::upper(Str::random(8));
        } while ($this->ctx->withoutScope(fn () => PaymentLink::where('code', $code)->exists()));

        $link = PaymentLink::create([
            'code' => $code, 'target_type' => $type, 'target_id' => $id, 'label' => $opts['label'] ?? $label,
            'amount' => $amount, 'currency' => $hotel->currency ?? 'XAF', 'allow_partial' => $opts['allow_partial'] ?? true,
            'payer_phone' => $opts['payer_phone'] ?? null, 'created_by' => $actor?->id,
            'expires_at' => now()->addDays((int) ($opts['valid_days'] ?? ($type === 'pos_check' ? 1 : 7))),
        ]);
        $this->bus->record('payment.link_created', $link->only(['id', 'code', 'target_type', 'target_id', 'amount']), 'payment_link', $link->id);

        return $link;
    }

    public function cancel(PaymentLink $link): PaymentLink
    {
        if (! $link->isPayable() && $link->status !== 'open') {
            throw new DomainException('This link can no longer be cancelled.');
        }
        $link->update(['status' => 'cancelled']);

        return $link;
    }

    public static function signature(string $code): string
    {
        return substr(hash_hmac('sha256', 'paylink|'.$code, (string) config('app.key')), 0, 10);
    }

    public function url(PaymentLink $link): string
    {
        return url("/pay/{$link->code}/".self::signature($link->code));
    }

    public function qrSvg(PaymentLink $link): string
    {
        return QrCode::svg($this->url($link), 4);
    }

    /** Public lookup by code + signature (no tenant context). */
    public function findPublic(string $code, string $sig): PaymentLink
    {
        abort_unless(hash_equals(self::signature($code), $sig), 404);

        return $this->ctx->withoutScope(fn () => PaymentLink::where('code', $code)->firstOrFail());
    }

    /** Amount reserved by intents still pending on the link. */
    public function pendingAmount(PaymentLink $link): int
    {
        return (int) PaymentIntent::where('payment_link_id', $link->id)->where('status', 'pending')->where('expires_at', '>', now())->sum('amount');
    }

    /** Start a guest payment on the link. Runs in the link's hotel context. */
    public function startPayment(PaymentLink $link, array $data, string $channel = 'public'): PaymentIntent
    {
        if (! $link->isPayable()) {
            throw new DomainException('This pay link is no longer payable.', 'link-closed');
        }
        $available = $link->balance() - $this->pendingAmount($link);
        $amount = (int) ($data['amount'] ?? $available);
        if ($amount <= 0 || $amount > $available) {
            throw new DomainException('The amount exceeds the balance of this link.');
        }
        if (! $link->allow_partial && $amount !== $link->balance()) {
            throw new DomainException('This link must be paid in full.');
        }

        return $this->payments->create([
            'provider' => $data['provider'], 'amount' => $amount, 'payment_link_id' => $link->id,
            'folio_id' => $link->target_type === 'folio' ? $link->target_id : null,
            'reservation_id' => $link->target_type === 'reservation' ? $link->target_id : null,
            'payer_phone' => $data['payer_phone'] ?? $link->payer_phone, 'payer_email' => $data['payer_email'] ?? null,
            'method' => $data['method'] ?? null, 'inbound_id' => $data['inbound_id'] ?? null,
        ], $channel);
    }

    /**
     * Post a succeeded intent to the link's document. Called inside PaymentService::settle()'s transaction.
     *
     * @return array{0: ?int, 1: ?int} folio id and folio line id when a folio was posted
     */
    public function apply(PaymentIntent $intent): array
    {
        $link = PaymentLink::lockForUpdate()->findOrFail($intent->payment_link_id);
        $ref = $intent->meta['provider_transaction_id'] ?? $intent->provider_reference ?? $intent->reference;
        $method = $intent->methodCode();
        $folioId = $lineId = null;

        switch ($link->target_type) {
            case 'folio':
            case 'reservation':
                $folio = $link->target_type === 'folio' ? Folio::findOrFail($link->target_id) : $this->folios->guestFolioFor(Reservation::findOrFail($link->target_id));
                $line = $this->folios->payment($folio, $method, $intent->amount, $ref, [
                    'description' => ($link->target_type === 'reservation' ? 'Deposit' : 'Pay link').' '.$link->code.' - '.$intent->provider,
                    'source_type' => 'payment_intent', 'source_id' => $intent->id,
                ]);
                [$folioId, $lineId] = [$folio->id, $line->id];
                if ($link->target_type === 'reservation') {
                    Reservation::whereKey($link->target_id)->increment('deposit_paid', $intent->amount);
                }
                break;
            case 'invoice':
                $invoice = Invoice::lockForUpdate()->findOrFail($link->target_id);
                if ($invoice->paid + $intent->amount > $invoice->total) {
                    throw new DomainException('Payment exceeds the invoice balance.');
                }
                $ledger = Folio::where('company_id', $invoice->company_id)->where('kind', 'city_ledger')->where('status', 'open')->firstOrFail();
                $line = $this->folios->payment($ledger, $method, $intent->amount, $ref, [
                    'description' => "Règlement facture {$invoice->number} (lien {$link->code})", 'source_type' => 'payment_intent', 'source_id' => $intent->id,
                ]);
                [$folioId, $lineId] = [$ledger->id, $line->id];
                $paid = $invoice->paid + $intent->amount;
                $invoice->update(['paid' => $paid, 'status' => $paid >= $invoice->total ? 'paid' : 'partially_paid']);
                $this->bus->record('receivables.payment_received', ['invoice_id' => $invoice->id, 'company_id' => $invoice->company_id, 'amount' => $intent->amount], 'invoice', $invoice->id);
                break;
            case 'pos_check':
                $account = PaymentMethod::where('code', $method)->value('account') ?? C::role('mobile_money');
                $this->ledger->post($account === C::role('mobile_money') ? 'MM' : 'BQ', Hotel::findOrFail($link->hotel_id)->business_date ?? now(),
                    "Acompte POS lien {$link->code} - {$intent->provider}", [
                        [$account, $intent->amount],
                        [C::role('customer_deposits'), -$intent->amount],
                    ], ['source_type' => 'payment_intent', 'source_id' => $intent->id]);
                break;
        }

        $paid = $link->amount_paid + $intent->amount;
        $link->update(['amount_paid' => $paid, 'status' => $paid >= $link->amount ? 'paid' : 'partially_paid', 'paid_at' => $paid >= $link->amount ? now() : null]);

        if ($link->target_type === 'pos_check' && $paid >= $link->amount) {
            $this->settlePosCheck($link);
        }

        return [$folioId, $lineId];
    }

    /** All advances for the check are in: settle it with the PAYLINK tender (clears 419 to revenue). */
    private function settlePosCheck(PaymentLink $link): void
    {
        $check = Check::findOrFail($link->target_id);
        $advanced = (int) PaymentLink::where('target_type', 'pos_check')->where('target_id', $check->id)->sum('amount_paid');
        if ($check->status !== 'open' || $advanced !== (int) $check->total) {
            return; // check changed after the link was issued: advances stay on 419100 for the cashier to apply
        }
        PaymentMethod::firstOrCreate(['code' => self::POS_TENDER], [
            'name' => 'Lien de paiement / QR', 'kind' => 'prepaid', 'account' => C::role('customer_deposits'), 'provider' => null,
        ]);
        app(PosService::class)->settle($check, [['method' => self::POS_TENDER, 'amount' => (int) $check->total, 'reference' => $link->code]]);
    }

    /** @return string label for the link */
    private function assertPayable(string $type, int $id): string
    {
        return match ($type) {
            'folio' => (function () use ($id) {
                $f = Folio::findOrFail($id);
                $f->status === 'open' ?: throw new DomainException("Folio {$f->number} is closed.", 'folio-closed');

                return "Folio {$f->number}";
            })(),
            'pos_check' => (function () use ($id) {
                $c = Check::findOrFail($id);
                $c->status === 'open' ?: throw new DomainException('This check is not open.');

                return "Check {$c->number}";
            })(),
            'invoice' => 'Invoice '.Invoice::findOrFail($id)->number,
            'reservation' => (function () use ($id) {
                $r = Reservation::findOrFail($id);
                ! in_array($r->status, ['cancelled', 'no_show', 'checked_out'], true) ?: throw new DomainException('This reservation can no longer take payments.');

                return "Booking {$r->number}";
            })(),
        };
    }

    public function present(PaymentLink $link, bool $withQr = true): array
    {
        return $link->only(['id', 'code', 'target_type', 'target_id', 'label', 'amount', 'amount_paid', 'currency', 'allow_partial', 'status', 'expires_at', 'paid_at', 'created_at'])
            + ['balance' => $link->balance(), 'url' => $this->url($link)] + ($withQr ? ['qr_svg' => $this->qrSvg($link)] : []);
    }

    /** Run as the link's hotel with no actor. */
    public function asHotel(int $hotelId, callable $fn): mixed
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $hotel = $this->ctx->withoutScope(fn () => Hotel::findOrFail($hotelId));
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        try {
            return DB::transaction($fn);
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}

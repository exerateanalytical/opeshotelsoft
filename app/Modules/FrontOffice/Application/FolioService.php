<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\CashierShift;
use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\FrontOffice\Domain\FolioRoutingRule;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\Guests\Domain\Company;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Folio aggregate: charges, payments, transfers and reversals.
 * Every posting emits folio.line_posted, which Accounting turns into a
 * balanced journal entry.
 */
class FolioService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    public function open(string $kind, string $name, ?Reservation $reservation = null, ?int $guestId = null, ?int $companyId = null): Folio
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $folio = Folio::create([
            'number' => NumberSequence::next($hotel->id, 'folio', 'F'),
            'kind' => $kind,
            'reservation_id' => $reservation?->id,
            'guest_id' => $guestId ?? $reservation?->guest_id,
            'company_id' => $companyId,
            'name' => $name,
            'currency' => $hotel->currency,
        ])->refresh();
        $this->bus->record('folio.opened', $folio->only(['id', 'number', 'kind', 'reservation_id', 'guest_id', 'company_id']), 'folio', $folio->id);

        return $folio;
    }

    public function guestFolioFor(Reservation $r): Folio
    {
        return Folio::where('reservation_id', $r->id)->where('kind', 'guest')->where('status', 'open')->first()
            ?? $this->open('guest', $r->guest->full_name.' / '.$r->number, $r);
    }

    /**
     * Post a charge. Amount is gross (tax-inclusive) when the tax code says so.
     *
     * @param  array{source_type?:string, source_id?:string|int, reference?:string, business_date?:string, description?:string, quantity?:float, nights?:int}  $opts
     */
    public function charge(Folio $folio, string $chargeCode, int $unitAmount, array $opts = []): FolioLine
    {
        $this->assertOpen($folio);
        $code = ChargeCode::where('code', $chargeCode)->where('active', true)->first()
            ?? throw new DomainException("Unknown charge code $chargeCode.");
        if (! ($opts['no_routing'] ?? false)) {
            $folio = $this->route($folio, $code);
        }
        $qty = $opts['quantity'] ?? 1;
        $gross = (int) round($unitAmount * $qty);
        [$taxTotal, $breakdown, $gross] = TaxCalculator::compute($code, $gross, $opts['nights'] ?? 1);

        return $this->post($folio, [
            'type' => 'charge',
            'code' => $code->code,
            'department' => $code->department,
            'description' => $opts['description'] ?? $code->name,
            'quantity' => $qty,
            'amount' => $gross,
            'tax_amount' => $taxTotal,
            'tax_breakdown' => $breakdown,
            'account' => $code->revenue_account,
        ] + array_intersect_key($opts, array_flip(['source_type', 'source_id', 'reference', 'business_date'])));
    }

    public function payment(Folio $folio, string $methodCode, int $amount, ?string $reference = null, array $opts = []): FolioLine
    {
        $this->assertOpen($folio);
        if ($amount <= 0) {
            throw new DomainException('Payment amount must be positive.');
        }
        $method = PaymentMethod::where('code', $methodCode)->where('active', true)->first()
            ?? throw new DomainException("Unknown payment method $methodCode.");
        if ($method->kind === 'city_ledger') {
            throw new DomainException('Use transfer to city ledger for company billing.');
        }

        return $this->post($folio, [
            'type' => 'payment',
            'code' => $method->code,
            'department' => 'payment',
            'description' => $opts['description'] ?? $method->name,
            'amount' => -$amount,
            'account' => $method->account,
            'reference' => $reference,
            'cashier_shift_id' => $this->requireShift($method)?->id,
        ] + array_intersect_key($opts, array_flip(['source_type', 'source_id', 'business_date'])));
    }

    public function refund(Folio $folio, string $methodCode, int $amount, string $reason): FolioLine
    {
        $this->assertOpen($folio);
        $method = PaymentMethod::where('code', $methodCode)->firstOrFail();
        if ($folio->balance > -$amount) {
            throw new DomainException('A refund cannot exceed the credit balance on the folio.');
        }

        return $this->post($folio, [
            'type' => 'refund', 'code' => $method->code, 'department' => 'payment',
            'description' => 'Refund - '.$method->name, 'amount' => $amount, 'account' => $method->account,
            'reason' => $reason, 'cashier_shift_id' => $this->requireShift($method)?->id,
        ]);
    }

    /** Reverse a line (void). The original stays; an opposite line is added. */
    public function reverse(FolioLine $line, string $reason): FolioLine
    {
        $folio = $line->folio;
        $this->assertOpen($folio);
        if ($line->reversed || $line->type === 'reversal') {
            throw new DomainException('This line is already reversed.');
        }
        if (in_array($line->type, ['transfer_in', 'transfer_out'], true)) {
            throw new DomainException('Transfers are corrected by transferring back.');
        }

        return DB::transaction(function () use ($line, $folio, $reason) {
            $rev = $this->post($folio, [
                'type' => 'reversal', 'code' => $line->code, 'department' => $line->department,
                'description' => 'Reversal: '.$line->description, 'quantity' => $line->quantity,
                'amount' => -$line->amount, 'tax_amount' => -$line->tax_amount,
                'tax_breakdown' => array_map(fn ($t) => ['amount' => -$t['amount']] + $t, $line->tax_breakdown ?? []),
                'account' => $line->account, 'reverses_line_id' => $line->id, 'reason' => $reason,
                'cashier_shift_id' => $line->cashier_shift_id,
            ]);
            $line->forceFill(['reversed' => true])->save();

            return $rev;
        });
    }

    /** Move charges (or a balance) from one folio to another, e.g. extras to guest, room to company. */
    public function transfer(Folio $from, Folio $to, int $amount, string $description = 'Transfer'): array
    {
        $this->assertOpen($from);
        $this->assertOpen($to);
        if ($amount === 0 || $from->id === $to->id) {
            throw new DomainException('Nothing to transfer.');
        }

        return DB::transaction(fn () => [
            $this->post($from, ['type' => 'transfer_out', 'description' => "$description → {$to->number}", 'amount' => -$amount, 'account' => 'transfer', 'reference' => $to->number]),
            $this->post($to, ['type' => 'transfer_in', 'description' => "$description ← {$from->number}", 'amount' => $amount, 'account' => 'transfer', 'reference' => $from->number]),
        ]);
    }

    /** Settle a folio balance to a company's city-ledger account (credit billing). */
    public function transferToCityLedger(Folio $folio, Company $company): Folio
    {
        if (! $company->city_ledger) {
            throw new DomainException("{$company->name} is not approved for credit billing.");
        }
        $outstanding = (int) DB::table('folios')->where('company_id', $company->id)->where('kind', 'city_ledger')->where('status', 'open')->sum('balance');
        if ($company->credit_limit > 0 && $outstanding + $folio->balance > $company->credit_limit) {
            throw new DomainException("This would exceed {$company->name}'s credit limit.", 'credit-limit');
        }
        $ledger = Folio::where('company_id', $company->id)->where('kind', 'city_ledger')->where('status', 'open')->first()
            ?? $this->open('city_ledger', 'City ledger - '.$company->name, null, null, $company->id);
        $this->transfer($folio, $ledger, $folio->fresh()->balance, 'Direct bill');

        return $ledger->fresh();
    }

    public function close(Folio $folio): Folio
    {
        $folio = $folio->fresh();
        if ($folio->balance !== 0) {
            throw new DomainException("Folio {$folio->number} has a balance of {$folio->balance} {$folio->currency}. Settle or transfer it first.", 'folio-unbalanced');
        }
        $folio->update(['status' => 'closed', 'closed_at' => now()]);
        $this->bus->record('folio.closed', ['id' => $folio->id, 'number' => $folio->number], 'folio', $folio->id);

        return $folio;
    }

    /** Issue a legal invoice for a folio (gapless number). */
    public function invoice(Folio $folio, array $billTo = []): Invoice
    {
        return DB::transaction(function () use ($folio, $billTo) {
            // City-ledger folios carry transferred stays (transfer lines); guest folios carry charges.
            $lines = $folio->lines()->whereIn('type', ['charge', 'reversal', 'transfer_in', 'transfer_out'])->get();
            $taxes = [];
            foreach ($lines as $l) {
                foreach ($l->tax_breakdown ?? [] as $t) {
                    $taxes[$t['code']] = ['code' => $t['code'], 'name' => $t['name'], 'amount' => ($taxes[$t['code']]['amount'] ?? 0) + $t['amount']];
                }
            }
            $company = $folio->company_id ? Company::find($folio->company_id) : null;
            $invoice = Invoice::create([
                'number' => NumberSequence::next($folio->hotel_id, 'invoice', 'FA'),
                'folio_id' => $folio->id,
                'company_id' => $folio->company_id,
                'guest_id' => $folio->guest_id,
                'bill_to_name' => $billTo['name'] ?? $company?->name ?? $folio->name,
                'bill_to_tax_id' => $billTo['tax_id'] ?? $company?->tax_id,
                'bill_to_address' => $billTo['address'] ?? $company?->address,
                'issue_date' => Hotel::find($folio->hotel_id)->business_date,
                'due_date' => $company ? now()->addDays($company->payment_terms_days) : null,
                'currency' => $folio->currency,
                'total' => (int) $lines->sum('amount'),
                'tax_total' => (int) $lines->sum('tax_amount'),
                'paid' => (int) -$folio->lines()->whereIn('type', ['payment', 'refund'])->sum('amount'),
                'lines' => $lines->map(fn ($l) => $l->only(['business_date', 'description', 'quantity', 'amount', 'tax_amount', 'department']))->values(),
                'taxes' => array_values($taxes),
                'issued_by' => $this->ctx->actor()?->id,
            ]);
            $this->bus->record('invoice.issued', $invoice->only(['id', 'number', 'folio_id', 'company_id', 'total', 'tax_total', 'currency']), 'invoice', $invoice->id);

            return $invoice;
        });
    }

    private function post(Folio $folio, array $line): FolioLine
    {
        return DB::transaction(function () use ($folio, $line) {
            $folio = Folio::lockForUpdate()->findOrFail($folio->id);
            $hotel = Hotel::findOrFail($folio->hotel_id);
            $record = FolioLine::create($line + [
                'folio_id' => $folio->id,
                'hotel_id' => $folio->hotel_id,
                'business_date' => $line['business_date'] ?? $hotel->business_date,
                'user_id' => $this->ctx->actor()?->id,
            ]);
            $folio->increment('balance', $record->amount);
            $this->bus->record('folio.line_posted', [
                'line_id' => $record->id, 'folio_id' => $folio->id, 'folio_number' => $folio->number, 'folio_kind' => $folio->kind,
                'company_id' => $folio->company_id, 'type' => $record->type, 'code' => $record->code,
                'department' => $record->department, 'amount' => $record->amount, 'tax_amount' => $record->tax_amount,
                'tax_breakdown' => $record->tax_breakdown, 'account' => $record->account,
                'business_date' => $record->business_date->toDateString(), 'description' => $record->description,
                'currency' => $folio->currency, 'reservation_id' => $folio->reservation_id, 'guest_id' => $folio->guest_id,
            ], 'folio', $folio->id);

            return $record;
        });
    }

    /**
     * Routing rules of the folio's reservation: a charge code rule wins over a
     * department rule, which wins over a catch-all. Only guest folios route.
     */
    public function route(Folio $folio, ChargeCode $code): Folio
    {
        if (! $folio->reservation_id || $folio->kind !== 'guest') {
            return $folio;
        }
        $rule = FolioRoutingRule::where('reservation_id', $folio->reservation_id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('match_type', 'charge_code')->where('match_value', $code->code))
                ->orWhere(fn ($w) => $w->where('match_type', 'department')->where('match_value', $code->department))
                ->orWhere('match_type', 'all'))
            ->orderByRaw("case match_type when 'charge_code' then 0 when 'department' then 1 else 2 end")
            ->orderBy('id')->first();
        if (! $rule || $rule->target_folio_id === $folio->id) {
            return $folio;
        }
        $target = Folio::find($rule->target_folio_id);

        return $target && $target->status === 'open' ? $target : $folio;
    }

    private function requireShift(PaymentMethod $method): ?CashierShift
    {
        $actor = $this->ctx->actor();
        if (! $actor || ! in_array($method->kind, ['cash', 'card', 'mobile_money', 'cheque'], true)) {
            return null;
        }

        return CashierShift::where('user_id', $actor->id)->where('status', 'open')->first()
            ?? throw new DomainException('Open a cashier shift before taking payments.', 'shift-required');
    }

    private function assertOpen(Folio $folio): void
    {
        if ($folio->status !== 'open') {
            throw new DomainException("Folio {$folio->number} is closed.", 'folio-closed');
        }
    }
}

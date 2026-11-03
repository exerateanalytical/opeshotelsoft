<?php

namespace App\Modules\Documents\Money;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Documents\Application\IssuedDocuments;
use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\FrontOffice\Domain\TaxCode;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Credit note (avoir): a gapless-numbered Invoice of kind credit_note linked to the invoice it credits.
 * Posts a balanced reversing journal entry (debit revenue and tax, credit the receivable) and flips the verify page
 * of the original invoice to "replaced by <avoir>" when the invoice is credited in full. Nothing is deleted.
 */
class CreditNotes
{
    public function __construct(private Ledger $ledger, private EventBus $bus, private TenantContext $ctx, private IssuedDocuments $issued, private Sheets $sheets, private Printer $printer) {}

    /** Credited so far against an invoice. */
    public function credited(Invoice $inv): int
    {
        return (int) Invoice::where('kind', 'credit_note')->where('credits_invoice_id', $inv->id)->sum('total');
    }

    public function issue(Invoice $inv, string $reason, ?int $amount = null): Invoice
    {
        if ($inv->kind !== 'invoice') {
            throw new DomainException('Only an invoice can be credited.');
        }
        if ($inv->status === 'cancelled') {
            throw new DomainException('This invoice is already cancelled.');
        }
        $already = $this->credited($inv);
        $room = (int) $inv->total - $already;
        $amount ??= $room;
        if ($amount <= 0 || $amount > $room) {
            throw new DomainException("A credit note cannot exceed the uncredited invoice balance ($room).", 'credit-exceeds');
        }
        $full = $amount === $room;
        $p = $inv->total > 0 ? $amount / $inv->total : 0;

        $note = DB::transaction(function () use ($inv, $reason, $amount, $p) {
            $hotel = Hotel::findOrFail($inv->hotel_id);
            $taxSum = 0;
            $debits = [];
            foreach ($inv->lines as $l) {
                $acct = ChargeCode::where('department', $l['department'] ?? '')->value('revenue_account') ?: ChargeCode::where('active', true)->value('revenue_account');
                $v = (int) round(((int) $l['amount'] - (int) ($l['tax_amount'] ?? 0)) * $p);
                $debits[] = [$acct, $v, ['department' => $l['department'] ?? null]];
            }
            $taxes = [];
            foreach ($inv->taxes ?? [] as $t) {
                $v = (int) round((int) $t['amount'] * $p);
                $taxes[] = ['code' => $t['code'], 'name' => $t['name'], 'amount' => $v];
                $debits[] = [TaxCode::where('code', $t['code'])->value('account') ?: C::role('vat_collected'), $v];
                $taxSum += $v;
            }
            // Rounding residue goes to the first line so the entry always balances against the credited amount.
            if ($debits) {
                $debits[0][1] += $amount - array_sum(array_column($debits, 1));
            }
            $receivable = $inv->company_id ? C::role('city_ledger') : C::role('guest_ledger');
            $partner = $inv->company_id ? ['partner_type' => 'company', 'partner_id' => $inv->company_id] : ['partner_type' => 'guest', 'partner_id' => $inv->guest_id];
            $number = NumberSequence::next($hotel->id, 'credit_note', 'AV');

            $note = Invoice::create([
                'number' => $number, 'kind' => 'credit_note', 'folio_id' => $inv->folio_id, 'company_id' => $inv->company_id, 'guest_id' => $inv->guest_id,
                'bill_to_name' => $inv->bill_to_name, 'bill_to_tax_id' => $inv->bill_to_tax_id, 'bill_to_address' => $inv->bill_to_address,
                'issue_date' => $hotel->business_date, 'currency' => $inv->currency, 'total' => $amount, 'tax_total' => $taxSum, 'paid' => 0,
                'lines' => [['description' => 'Avoir sur facture '.$inv->number, 'amount' => $amount, 'tax_amount' => $taxSum, 'reason' => $reason, 'department' => null]],
                'taxes' => $taxes, 'status' => 'applied', 'credits_invoice_id' => $inv->id, 'issued_by' => $this->ctx->actor()?->id,
            ]);
            $entry = $this->ledger->post('VT', $hotel->business_date, "Avoir $number sur facture {$inv->number}: $reason", array_merge($debits, [[$receivable, -$amount, $partner]]), [
                'source_type' => 'credit_note', 'source_id' => $note->id, 'source_event' => 'credit_note:'.$note->id,
            ]);
            $this->bus->record('invoice.credited', ['id' => $note->id, 'number' => $number, 'credits' => $inv->number, 'amount' => $amount, 'entry' => $entry?->number], 'invoice', $note->id);

            return $note;
        });

        if ($full) {
            $inv->update(['status' => 'cancelled']);
        } elseif ($inv->company_id) {
            $paid = min((int) $inv->total, (int) $inv->paid + $amount);
            $inv->update(['paid' => $paid, 'status' => $paid >= $inv->total ? 'paid' : 'partially_paid']);
        }
        // Issue the credit note now (French, legal) so the original invoice's verify page can point to it.
        $doc = $this->printer->render('credit_note', $this->sheets->creditNote($note->id), 'fr');
        if ($full && $doc->record) {
            $this->issued->replaceSource('invoice', 'invoice', $inv->id, $doc->record, 'Avoir '.$note->number);
        }

        return $note->refresh();
    }
}

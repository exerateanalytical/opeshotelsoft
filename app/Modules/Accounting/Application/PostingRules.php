<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Platform\Events\StoredEvent;

/**
 * Translates operational events from other bounded contexts into
 * general-ledger entries. Each handler is idempotent via source_event.
 * Signed lines: + debit, - credit.
 */
class PostingRules
{
    public function __construct(private Ledger $ledger) {}

    /** folio.line_posted — front office main courante. */
    public function folioLine(StoredEvent $e): void
    {
        $p = $e->payload;
        $receivable = $p['folio_kind'] === 'city_ledger' ? C::role('city_ledger') : C::role('guest_ledger');
        $partner = $p['folio_kind'] === 'city_ledger'
            ? ['partner_type' => 'company', 'partner_id' => $p['company_id']]
            : ['partner_type' => 'guest', 'partner_id' => $p['guest_id']];
        $amount = (int) $p['amount'];
        $desc = "{$p['folio_number']} - {$p['description']}";
        $meta = ['source_type' => 'folio_line', 'source_id' => $p['line_id'], 'source_event' => $e->uuid];
        $isMoney = in_array($p['department'], ['payment'], true);

        $lines = match (true) {
            in_array($p['type'], ['charge', 'reversal'], true) && ! $isMoney => $this->revenueLines($receivable, $partner, $amount, $p),
            in_array($p['type'], ['payment', 'refund', 'reversal'], true) && $isMoney => [
                [$p['account'], -$amount],
                [$receivable, $amount, $partner],
            ],
            $p['type'] === 'transfer_in' && $p['folio_kind'] === 'city_ledger' => [
                [C::role('city_ledger'), $amount, $partner],
                [C::role('guest_ledger'), -$amount],
            ],
            $p['type'] === 'transfer_out' && $p['folio_kind'] === 'city_ledger' => [
                [C::role('city_ledger'), $amount, $partner],
                [C::role('guest_ledger'), -$amount],
            ],
            default => [], // guest-to-guest transfers stay inside the guest ledger
        };

        if ($lines) {
            $journal = $isMoney ? $this->moneyJournal($p['account']) : 'VT';
            $this->ledger->post($journal, $p['business_date'], $desc, $lines, $meta);
        }
    }

    /** pos.check_settled — direct (non room-charge) outlet sales. */
    public function posSettled(StoredEvent $e): void
    {
        $p = $e->payload;
        if (($p['settlement'] ?? '') === 'room_charge') {
            return; // the folio posting carries the revenue
        }
        $lines = [];
        foreach ($p['payments'] as $pay) {
            $lines[] = [$pay['account'], $pay['amount']];
        }
        foreach ($p['revenue'] as $rev) {
            $lines[] = [$rev['account'], -$rev['net'], ['department' => $rev['department'], 'outlet' => $p['outlet'] ?? null]];
        }
        foreach ($p['taxes'] as $tax) {
            $lines[] = [$tax['account'], -$tax['amount']];
        }
        $this->ledger->post('VT', $p['business_date'], "POS {$p['outlet']} check {$p['number']}", $this->round($lines), [
            'source_type' => 'pos_check', 'source_id' => $p['id'], 'source_event' => $e->uuid,
        ]);
    }

    /** stock.movement_posted — permanent inventory. */
    public function stockMovement(StoredEvent $e): void
    {
        $p = $e->payload;
        $value = (int) $p['value'];
        if ($value === 0) {
            return;
        }
        $inventory = $p['inventory_account'];
        $lines = match ($p['kind']) {
            'receipt' => [[$inventory, $value], [C::role('supplier_accrual'), -$value, ['partner_type' => 'supplier', 'partner_id' => $p['supplier_id'] ?? null]]],
            'issue', 'sale' => [[$p['cost_account'], $value, ['department' => $p['department'] ?? null]], [$inventory, -$value]],
            'wastage' => [[$p['cost_account'], $value, ['department' => $p['department'] ?? null]], [$inventory, -$value]],
            'adjustment' => [[C::role('stock_variance'), -$value], [$inventory, $value]], // value signed: + gain, - loss
            'return' => [[C::role('supplier_accrual'), $value], [$inventory, -$value]],
            default => [], // transfers between stores of the same hotel have no GL effect
        };
        if ($lines) {
            $this->ledger->post('ST', $p['date'], $p['description'], $lines, ['source_type' => 'stock_movement', 'source_id' => $p['id'], 'source_event' => $e->uuid]);
        }
    }

    /** payables.invoice_approved — supplier invoice matched to receipts. */
    public function supplierInvoice(StoredEvent $e): void
    {
        $p = $e->payload;
        $partner = ['partner_type' => 'supplier', 'partner_id' => $p['supplier_id']];
        $lines = [[C::role('supplier'), -$p['total'], $partner]];
        if ($p['tax'] > 0) {
            $lines[] = [C::role('vat_deductible'), $p['tax']];
        }
        foreach ($p['lines'] as $l) {
            $lines[] = [$l['account'], $l['amount'], $partner];
        }
        $this->ledger->post('AC', $p['date'], "Facture fournisseur {$p['reference']}", $this->round($lines), [
            'source_type' => 'supplier_invoice', 'source_id' => $p['id'], 'source_event' => $e->uuid,
        ]);
    }

    /** payables.payment_made */
    public function supplierPayment(StoredEvent $e): void
    {
        $p = $e->payload;
        $wht = (int) ($p['withheld'] ?? 0); // A-B: withholding tax kept back from the supplier, owed to the state
        $this->ledger->post($this->moneyJournal($p['account']), $p['date'], "Règlement fournisseur {$p['reference']}", array_values(array_filter([
            [C::role('supplier'), $p['amount'], ['partner_type' => 'supplier', 'partner_id' => $p['supplier_id']]],
            [$p['account'], -($p['amount'] - $wht)],
            $wht > 0 ? [\App\Modules\Accounting\Tax\Withholding::PAYABLE, -$wht] : null,
        ])),['source_type' => 'supplier_payment', 'source_id' => $p['id'], 'source_event' => $e->uuid]);
    }

    /** payroll.run_approved */
    public function payroll(StoredEvent $e): void
    {
        $p = $e->payload;
        $lines = [
            [C::role('salaries'), $p['gross']],
            [C::role('employer_charges'), $p['employer_contributions']],
            [C::role('wages_payable'), -$p['net']],
            [C::role('social_security'), -($p['employee_social'] + $p['employer_social'])],
            [C::role('income_tax_withheld'), -$p['taxes_withheld']],
        ];
        if (($p['advances_recovered'] ?? 0) > 0) {
            $lines[] = [C::role('staff_advances'), -$p['advances_recovered']];
        }
        if (($p['other_employer'] ?? 0) > 0) {
            $lines[] = [C::role('income_tax_withheld'), -$p['other_employer']];
        }
        $this->ledger->post('PA', $p['date'], "Paie {$p['period']}", $this->round($lines), [
            'source_type' => 'payroll_run', 'source_id' => $p['id'], 'source_event' => $e->uuid,
        ]);
    }

    /** payroll.run_paid */
    public function payrollPaid(StoredEvent $e): void
    {
        $p = $e->payload;
        $this->ledger->post($this->moneyJournal($p['account']), $p['date'], "Virement salaires {$p['period']}", [
            [C::role('wages_payable'), $p['amount']],
            [$p['account'], -$p['amount']],
        ], ['source_type' => 'payroll_payment', 'source_id' => $p['id'], 'source_event' => $e->uuid]);
    }

    /** loyalty.points_earned / loyalty.points_redeemed — points liability. */
    public function loyalty(StoredEvent $e): void
    {
        $p = $e->payload;
        $value = (int) ($p['liability_value'] ?? 0);
        if ($value === 0 || empty($p['hotel_id'] ?? $e->hotel_id)) {
            return;
        }
        $lines = $e->type === 'loyalty.points_earned'
            ? [[C::role('loyalty_expense'), $value], [C::role('loyalty_liability'), -$value]]
            : [[C::role('loyalty_liability'), $value], [C::role('loyalty_expense'), -$value]];
        $this->ledger->post('OD', now()->toDateString(), "Fidélité {$e->type} #{$p['membership_id']}", $lines, [
            'source_type' => 'loyalty', 'source_id' => $p['transaction_id'], 'source_event' => $e->uuid,
        ]);
    }

    private function revenueLines(string $receivable, array $partner, int $gross, array $p): array
    {
        $lines = [[$receivable, $gross, $partner]];
        $taxTotal = 0;
        foreach ($p['tax_breakdown'] ?? [] as $t) {
            $lines[] = [$t['account'], -(int) $t['amount']];
            $taxTotal += (int) $t['amount'];
        }
        $lines[] = [$p['account'], -($gross - $taxTotal), ['department' => $p['department']]];

        return $lines;
    }

    private function moneyJournal(string $account): string
    {
        return match ($account) {
            C::role('cash') => 'CA',
            C::role('mobile_money') => 'MM',
            C::role('bank'), C::role('card_clearing') => 'BQ',
            default => 'OD',
        };
    }

    /** Push any rounding residue (±few units) to the rounding account. */
    private function round(array $lines): array
    {
        $sum = array_sum(array_map(fn ($l) => $l[1], $lines));
        if ($sum !== 0 && abs($sum) <= 10) {
            $lines[] = [C::role('rounding'), -$sum];
        }

        return $lines;
    }
}

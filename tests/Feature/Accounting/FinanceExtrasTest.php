<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\JournalLine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceExtrasTest extends TestCase
{
    private function entry(string $date, array $lines, string $journal = 'OD', string $desc = 'Test'): array
    {
        return $this->postJson('/api/v1/accounting/entries', ['journal' => $journal, 'date' => $date, 'description' => $desc, 'lines' => $lines])->assertCreated()->json();
    }

    private function bank(): array
    {
        return $this->postJson('/api/v1/accounting/bank-accounts', ['name' => 'Afriland', 'kind' => 'bank', 'gl_account' => '521100'])->assertCreated()->json();
    }

    // ---------------- Reconciliation ----------------

    public function test_statement_import_auto_match_manual_match_and_differences(): void
    {
        $bank = $this->bank();
        $this->entry('2026-03-02', [['account' => '521100', 'debit' => 150_000], ['account' => '706800', 'credit' => 150_000]], 'BQ', 'Virement client VIR-001');
        $this->entry('2026-03-05', [['account' => '601100', 'debit' => 40_000], ['account' => '521100', 'credit' => 40_000]], 'BQ', 'Chèque fournisseur');
        $this->entry('2026-03-20', [['account' => '521100', 'debit' => 9_999], ['account' => '706800', 'credit' => 9_999]], 'BQ', 'Not on statement');

        $csv = "Date;Libellé;Réf;Montant;Solde\n"
            ."04/03/2026;VIR CLIENT;VIR-001;150 000;1 150 000\n"
            ."06/03/2026;CHQ 123;CHQ123;-40 000;1 110 000\n"
            ."31/03/2026;FRAIS TENUE COMPTE;FR03;-5 000;1 125 000\n"
            ."25/03/2026;ENCAISSEMENT;X9;20 000;1 130 000\n";
        $mapping = ['date' => 'Date', 'description' => 'Libellé', 'reference' => 'Réf', 'amount' => 'Montant', 'balance' => 'Solde'];
        $import = $this->postJson("/api/v1/accounting/bank-accounts/{$bank['id']}/statements", ['csv' => $csv, 'mapping' => $mapping])->assertCreated()->json();
        $this->assertSame(4, $import['rows']);
        $this->assertSame(1_000_000, $import['opening_balance']);
        // Re-import is idempotent.
        $again = $this->postJson("/api/v1/accounting/bank-accounts/{$bank['id']}/statements", ['csv' => $csv, 'mapping' => $mapping])->assertCreated()->json();
        $this->assertSame(0, $again['rows']);
        $this->assertSame(4, $again['skipped']);

        $res = $this->postJson("/api/v1/accounting/bank-accounts/{$bank['id']}/auto-match")->assertOk()->json();
        $this->assertSame(2, $res['matched']);
        $this->assertSame(2, $res['unmatched']);

        $lines = collect($this->getJson("/api/v1/accounting/bank-accounts/{$bank['id']}/statement-lines?status=unmatched")->assertOk()->json());
        $charges = $lines->firstWhere('reference', 'FR03');
        $receipt = $lines->firstWhere('reference', 'X9');

        // Bank charges posted as a journal entry and matched.
        $this->postJson("/api/v1/accounting/statement-lines/{$charges['id']}/post-difference", [])->assertCreated()->assertJsonPath('match_kind', 'posted');
        $bal = $this->assertBooksBalance();
        $this->assertSame(5_000, $bal['631100']);

        // Manual match: amount mismatch refused, then a correct ledger line is matched, unmatched, re-matched.
        $wrong = JournalLine::where('account_code', '521100')->where('debit', 9_999)->first();
        $this->postJson("/api/v1/accounting/statement-lines/{$receipt['id']}/match", ['journal_line_id' => $wrong->id])->assertStatus(422);
        $this->entry('2026-03-25', [['account' => '521100', 'debit' => 20_000], ['account' => '706800', 'credit' => 20_000]], 'BQ', 'Encaissement');
        $right = JournalLine::where('account_code', '521100')->where('debit', 20_000)->first();
        $this->postJson("/api/v1/accounting/statement-lines/{$receipt['id']}/match", ['journal_line_id' => $right->id])->assertOk()->assertJsonPath('match_kind', 'manual');
        $this->assertSame('BSL-'.$receipt['id'], $right->fresh()->reconciliation_ref);
        $this->postJson("/api/v1/accounting/statement-lines/{$receipt['id']}/unmatch")->assertOk()->assertJsonPath('journal_line_id', null);
        $this->assertNull($right->fresh()->reconciliation_ref);
        $this->postJson("/api/v1/accounting/statement-lines/{$receipt['id']}/match", ['journal_line_id' => $right->id])->assertOk();

        $report = $this->getJson("/api/v1/accounting/bank-accounts/{$bank['id']}/reconciliation?from=2026-03-01&to=2026-03-31")->assertOk()->json();
        $this->assertSame(1_000_000, $report['statement_opening_balance']);
        $this->assertSame(1_125_000, $report['statement_closing_balance']);
        $this->assertSame(150_000 - 40_000 + 9_999 - 5_000 + 20_000, $report['book_closing_balance']);
        $this->assertCount(0, $report['unmatched_statement_lines']);
        $this->assertSame(9_999, $report['unmatched_ledger_total']);
        // Book balance has no opening entry (statement started at 1 000 000), so a difference remains equal to it.
        $this->assertSame(1_000_000, $report['difference']);
    }

    public function test_import_supports_debit_credit_columns_and_index_mapping(): void
    {
        $bank = $this->postJson('/api/v1/accounting/bank-accounts', ['name' => 'MTN MoMo', 'kind' => 'mobile_money', 'gl_account' => '521500'])->assertCreated()->json();
        $csv = "2026-04-01,TXN1,,25000\n2026-04-02,TXN2,1500,\n";
        $import = $this->postJson("/api/v1/accounting/bank-accounts/{$bank['id']}/statements", [
            'csv' => $csv, 'has_header' => false, 'mapping' => ['date' => 0, 'reference' => 1, 'debit' => 2, 'credit' => 3],
        ])->assertCreated()->json();
        $this->assertSame(2, $import['rows']);
        $amounts = collect($this->getJson("/api/v1/accounting/bank-accounts/{$bank['id']}/statement-lines")->json())->pluck('amount')->sort()->values()->all();
        $this->assertSame([-1500, 25000], $amounts);

        $this->postJson("/api/v1/accounting/bank-accounts/{$bank['id']}/statements", ['csv' => "a;b\n1;2", 'mapping' => ['date' => 'Missing', 'amount' => 'b']])->assertStatus(422);
    }

    public function test_reconciliation_requires_permission(): void
    {
        $bank = $this->bank();
        $this->actAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]), $this->hotel);
        $this->postJson("/api/v1/accounting/bank-accounts/{$bank['id']}/auto-match")->assertForbidden();
        $this->postJson('/api/v1/accounting/year-end/close', ['year' => 2025])->assertForbidden();
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 1])->assertForbidden();
    }

    // ---------------- Fixed assets ----------------

    private function assetClass(string $method = 'straight_line'): array
    {
        return $this->postJson('/api/v1/accounting/asset-classes', [
            'code' => 'MOB-'.substr($method, 0, 4), 'name' => 'Mobilier', 'asset_account' => '244000', 'depreciation_account' => '284400',
            'method' => $method, 'useful_life_months' => 60,
        ])->assertCreated()->json();
    }

    public function test_asset_class_requires_syscohada_accounts(): void
    {
        $this->postJson('/api/v1/accounting/asset-classes', ['code' => 'X', 'name' => 'X', 'asset_account' => '601100', 'depreciation_account' => '284400', 'method' => 'straight_line', 'useful_life_months' => 12])->assertStatus(422);
        $this->postJson('/api/v1/accounting/asset-classes', ['code' => 'X', 'name' => 'X', 'asset_account' => '244000', 'depreciation_account' => '244000', 'method' => 'straight_line', 'useful_life_months' => 12])->assertStatus(422);
    }

    public function test_straight_line_acquisition_depreciation_and_disposal_balance(): void
    {
        $class = $this->assetClass();
        $asset = $this->postJson('/api/v1/accounting/fixed-assets', ['asset_class_id' => $class['id'], 'name' => 'Lits chambre 101', 'acquired_on' => '2026-01-10', 'cost' => 6_000_000])->assertCreated()->json();
        $bal = $this->assertBooksBalance();
        $this->assertSame(6_000_000, $bal['244000']);
        $this->assertSame(-6_000_000, $bal['481000']);

        $run = $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 1])->assertCreated()->json();
        $this->assertSame(100_000, $run['total']);
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 1])->assertStatus(422);
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 2])->assertCreated();
        $bal = $this->assertBooksBalance();
        $this->assertSame(200_000, $bal['681000']);
        $this->assertSame(-200_000, $bal['284400']);

        // Sold for 5 900 000: book value 5 800 000, gain 100 000.
        $disposed = $this->postJson("/api/v1/accounting/fixed-assets/{$asset['id']}/dispose", ['date' => '2026-02-28', 'proceeds' => 5_900_000])->assertOk()->json();
        $this->assertSame(100_000, $disposed['disposal_result']);
        $bal = $this->assertBooksBalance();
        $this->assertSame(0, $bal['244000']);
        $this->assertSame(0, $bal['284400']);
        $this->assertSame(5_800_000, $bal['812000']);
        $this->assertSame(-5_900_000, $bal['822000']);
        $this->postJson("/api/v1/accounting/fixed-assets/{$asset['id']}/dispose", ['date' => '2026-03-01', 'proceeds' => 0])->assertStatus(422);
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 3])->assertCreated()->assertJsonPath('total', 0);
    }

    public function test_declining_balance_and_acquisition_from_supplier_invoice(): void
    {
        $class = $this->assetClass('declining_balance');
        $supplier = DB::table('suppliers')->insertGetId(['group_id' => $this->group->id, 'code' => 'MEU', 'name' => 'Meubles SA', 'created_at' => now(), 'updated_at' => now()]);
        $invoice = DB::table('supplier_invoices')->insertGetId([
            'group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'supplier_id' => $supplier, 'reference' => 'F-77', 'date' => '2026-01-05',
            'net_total' => 1_200_000, 'total' => 1_200_000, 'status' => 'approved',
            'expense_lines' => json_encode([['account' => '244000', 'amount' => 1_200_000, 'description' => 'Salon réception']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The approved invoice has already been posted by payables: simulate it.
        $this->entry('2026-01-05', [['account' => '244000', 'debit' => 1_200_000], ['account' => '401100', 'credit' => 1_200_000]], 'AC');

        $asset = $this->postJson('/api/v1/accounting/fixed-assets', ['asset_class_id' => $class['id'], 'supplier_invoice_id' => $invoice])->assertCreated()->json();
        $this->assertSame(1_200_000, $asset['cost']);
        $this->assertSame('Salon réception', $asset['name']);
        $this->assertNull($asset['acquisition_entry_id']);
        $this->postJson('/api/v1/accounting/fixed-assets', ['asset_class_id' => $class['id'], 'supplier_invoice_id' => $invoice])->assertStatus(422);

        // 60 months, coefficient 2: annual rate 40 %, month 1 = 1 200 000 × 40 % / 12 = 40 000 (> straight-line 20 000).
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 1])->assertCreated()->assertJsonPath('total', 40_000);
        // Month 2 on the reduced base: 1 160 000 × 40 % / 12 = 38 667.
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 2])->assertCreated()->assertJsonPath('total', 38_667);
        $bal = $this->assertBooksBalance();
        $this->assertSame(-78_667, $bal['284400']);
    }

    // ---------------- Reports ----------------

    public function test_cash_flow_reconciles_to_treasury_and_dsf_export(): void
    {
        $this->entry('2026-01-02', [['account' => '521100', 'debit' => 10_000_000], ['account' => '101000', 'credit' => 10_000_000]], 'BQ', 'Apport capital');
        $this->entry('2026-01-10', [['account' => '411200', 'debit' => 500_000], ['account' => '706100', 'credit' => 500_000]], 'VT');
        $this->entry('2026-01-20', [['account' => '521100', 'debit' => 300_000], ['account' => '411200', 'credit' => 300_000]], 'BQ');
        $class = $this->assetClass();
        $asset = $this->postJson('/api/v1/accounting/fixed-assets', ['asset_class_id' => $class['id'], 'name' => 'TV', 'acquired_on' => '2026-01-15', 'cost' => 600_000])->assertCreated()->json();
        $this->entry('2026-01-25', [['account' => '481000', 'debit' => 600_000], ['account' => '521100', 'credit' => 600_000]], 'BQ');
        $this->postJson('/api/v1/accounting/depreciation-runs', ['year' => 2026, 'month' => 1])->assertCreated();
        $this->postJson("/api/v1/accounting/fixed-assets/{$asset['id']}/dispose", ['date' => '2026-01-31', 'proceeds' => 550_000, 'proceeds_account' => '521100'])->assertOk();

        $cf = $this->getJson('/api/v1/accounting/reports/cash-flow?from=2026-01-01&to=2026-01-31')->assertOk()->json();
        $this->assertTrue($cf['check']);
        $this->assertSame(0, $cf['opening_treasury']);
        $this->assertSame(10_000_000 + 300_000 - 600_000 + 550_000, $cf['closing_treasury']);
        $sections = collect($cf['sections'])->keyBy('key');
        $this->assertSame(10_000_000, $sections['financing']['total']);
        $this->assertSame(-600_000 + 550_000, $sections['investing']['total']);
        $this->assertSame(300_000, $sections['operating']['total']);

        $dsf = $this->getJson('/api/v1/accounting/reports/dsf?year=2026')->assertOk()->json();
        $this->assertStringContainsString('GATE-3', $dsf['gate']);
        $lines = collect($dsf['lines'])->keyBy('ref');
        $this->assertSame(10_000_000, $lines['CA']['amount']);
        $this->assertSame(500_000, $lines['TC']['amount']);
        $this->assertSame([], $dsf['unmapped_accounts']);
        $csv = $this->get('/api/v1/accounting/reports/dsf?year=2026&format=csv')->assertOk();
        $this->assertStringContainsString('dsf_ref;label', $csv->getContent());
    }

    // ---------------- Foreign currency ----------------

    public function test_fx_revaluation_posts_gains_and_losses(): void
    {
        // Receivable 1 000 USD booked at 600; payable 500 EUR booked at 655.957.
        $this->entry('2026-05-02', [
            ['account' => '411200', 'debit' => 600_000, 'currency' => 'USD', 'fx_amount' => 1000, 'fx_rate' => 600, 'partner_type' => 'company', 'partner_id' => 1],
            ['account' => '706100', 'credit' => 600_000],
        ], 'VT');
        $this->entry('2026-05-03', [
            ['account' => '601100', 'debit' => 327_979],
            ['account' => '401100', 'credit' => 327_979, 'currency' => 'EUR', 'fx_amount' => 500, 'fx_rate' => 655.957],
        ], 'AC');

        $this->getJson('/api/v1/accounting/fx-revaluations/preview?date=2026-05-31')->assertStatus(422); // no rate anywhere
        DB::table('exchange_rates')->insert(['from' => 'EUR', 'to' => 'XAF', 'date' => '2026-05-31', 'rate' => 655.957]);
        $preview = $this->getJson('/api/v1/accounting/fx-revaluations/preview?date=2026-05-31&rates[USD]=620')->assertOk()->json();
        $this->assertSame(20_000, $preview['gain']);
        $this->assertSame(0, $preview['loss']);

        $run = $this->postJson('/api/v1/accounting/fx-revaluations', ['date' => '2026-05-31', 'rates' => ['USD' => 620, 'EUR' => 660]])->assertCreated()->json();
        $this->assertSame(20_000, $run['gain']);
        $this->assertSame(2_021, $run['loss']); // 500 × 660 = 330 000 vs 327 979
        $bal = $this->assertBooksBalance();
        $this->assertSame(-20_000, $bal['776000']);
        $this->assertSame(2_021, $bal['676000']);
        $this->assertSame(620_000, $bal['411200']);

        // Running again at the same rates changes nothing.
        $this->getJson('/api/v1/accounting/fx-revaluations/preview?date=2026-05-31&rates[USD]=620&rates[EUR]=660')->assertOk()->assertJsonPath('gain', 0)->assertJsonPath('loss', 0);
    }

    // ---------------- Year-end ----------------

    public function test_year_end_close_requires_closed_months_then_locks_and_carries_forward(): void
    {
        $this->entry('2025-01-02', [['account' => '521100', 'debit' => 2_000_000], ['account' => '101000', 'credit' => 2_000_000]], 'BQ');
        $this->entry('2025-06-10', [['account' => '521100', 'debit' => 800_000], ['account' => '706100', 'credit' => 800_000]], 'BQ');
        $this->entry('2025-07-10', [['account' => '605200', 'debit' => 300_000], ['account' => '521100', 'credit' => 300_000]], 'BQ');

        foreach (range(1, 11) as $m) {
            $this->postJson('/api/v1/accounting/periods/close', ['year' => 2025, 'month' => $m])->assertOk();
        }
        $this->postJson('/api/v1/accounting/year-end/close', ['year' => 2025])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, '12'));
        $this->getJson('/api/v1/accounting/year-end?year=2025')->assertOk()->assertJsonPath('open_months', [12])->assertJsonPath('can_close', false);
        $this->postJson('/api/v1/accounting/periods/close', ['year' => 2025, 'month' => 12])->assertOk();

        $fy = $this->postJson('/api/v1/accounting/year-end/close', ['year' => 2025])->assertCreated()->json();
        $this->assertSame(500_000, $fy['result']);
        $bal = $this->assertBooksBalance();
        $this->assertSame(0, $bal['706100']);
        $this->assertSame(0, $bal['605200']);
        $this->assertSame(-500_000, $bal['131000']);

        $status = $this->getJson('/api/v1/accounting/year-end?year=2025')->assertOk()->json();
        $this->assertTrue($status['locked']);
        $opening = collect($status['opening_balances_next_year'])->keyBy('account_code');
        $this->assertSame(2_500_000, (int) $opening['521100']['debit']);
        $this->assertSame(500_000, (int) $opening['131000']['credit']);

        // Locked: no posting even after a month is reopened; second close refused.
        DB::table('fiscal_periods')->where(['hotel_id' => $this->hotel->id, 'year' => 2025, 'month' => 12])->update(['status' => 'open']);
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => '2025-12-15', 'description' => 'Late', 'lines' => [
            ['account' => '605200', 'debit' => 1000], ['account' => '521100', 'credit' => 1000],
        ]])->assertStatus(422);
        $this->postJson('/api/v1/accounting/year-end/close', ['year' => 2025])->assertStatus(422);

        // Balance sheet still balances after close.
        $bs = $this->getJson('/api/v1/accounting/reports/balance-sheet?date=2026-01-01')->assertOk()->json();
        $this->assertSame($bs['total_assets'], $bs['total_liabilities_and_equity']);
    }
}

<?php

namespace Tests\Feature\Accounting;

use App\Modules\Stock\Domain\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxWithholdingTest extends TestCase
{
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::create(['code' => 'CONS', 'name' => 'Cabinet Conseil', 'tax_id' => 'M098765']);
    }

    private function approvedInvoice(int $net = 1_000_000, int $tax = 192_500, string $ref = 'FC-1'): array
    {
        $inv = $this->postJson('/api/v1/payables/invoices', ['supplier_id' => $this->supplier->id, 'reference' => $ref, 'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'net_total' => $net, 'tax_total' => $tax, 'expense_lines' => [['account' => '632000', 'amount' => $net]]])->assertCreated()->json();

        return $this->postJson("/api/v1/payables/invoices/{$inv['id']}/approve")->assertOk()->json();
    }

    public function test_withholding_is_taken_on_payment_booked_to_447_and_certified_then_remitted(): void
    {
        $this->putJson("/api/v1/accounting3/tax/suppliers/{$this->supplier->id}/profile", ['withholding_code' => 'NOPE'])->assertStatus(422);
        $rates = $this->getJson('/api/v1/accounting3/tax/withholding-rates')->assertOk()->json();
        $this->assertContains('RAS_SERV', array_column($rates, 'code'));
        $this->putJson("/api/v1/accounting3/tax/suppliers/{$this->supplier->id}/profile", ['withholding_code' => 'RAS_SERV', 'niu' => 'M098765X'])->assertOk();

        $inv = $this->approvedInvoice();
        $this->assertSame(1_192_500, $inv['total']);
        // Pay in two parts: base is the net (HT) share of each payment; the invoice is settled for the gross.
        $this->postJson("/api/v1/payables/invoices/{$inv['id']}/pay", ['amount' => 596_250, 'account' => '521100'])->assertOk();
        $paid = $this->postJson("/api/v1/payables/invoices/{$inv['id']}/pay", ['amount' => 596_250, 'account' => '521100'])->assertOk()->assertJsonPath('status', 'paid')->json();
        $this->assertSame(1_192_500, $paid['paid']);

        $pays = DB::table('supplier_payments')->orderBy('id')->get();
        $this->assertSame([27_500, 27_500], $pays->pluck('withheld')->map(fn ($v) => (int) $v)->all()); // 5.5% of 500,000
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['401100'] ?? 0);                 // supplier fully settled
        $this->assertSame(-55_000, $b['447100']);               // owed to the state
        $this->assertSame(-(1_192_500 - 55_000), $b['521100']); // bank paid net of the withholding

        $certs = $this->getJson('/api/v1/accounting3/tax/withholding-certificates')->assertOk()->json();
        $this->assertSame(['RAS-2026-0002', 'RAS-2026-0001'], array_column($certs, 'number'));
        $c = $this->getJson("/api/v1/accounting3/tax/withholding-certificates/{$certs[1]['id']}")->assertOk()->json();
        $this->assertSame('M098765X', $c['supplier_niu']);
        $this->assertSame(500_000, $c['base']);
        $this->assertSame(27_500, $c['amount']);
        $this->assertSame(64, strlen($c['hash']));

        // Remit both certificates to the tax office: Dr 447100 / Cr bank.
        $ids = array_column($certs, 'id');
        $r = $this->postJson('/api/v1/accounting3/tax/withholding-certificates/remit', ['ids' => $ids, 'date' => now()->toDateString(), 'account' => '521100', 'reference' => 'QUIT-9'])->assertOk()->json();
        $this->assertSame(55_000, $r['total']);
        $this->postJson('/api/v1/accounting3/tax/withholding-certificates/remit', ['ids' => $ids, 'date' => now()->toDateString(), 'account' => '521100'])->assertStatus(422);
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['447100'] ?? 0);
        $this->assertSame('remitted', $this->getJson('/api/v1/accounting3/tax/withholding-certificates?status=remitted')->json('0.status'));
    }

    public function test_exempt_and_unprofiled_suppliers_pay_in_full_and_threshold_applies(): void
    {
        $inv = $this->approvedInvoice(500_000, 0, 'NOPROFILE');
        $this->postJson("/api/v1/payables/invoices/{$inv['id']}/pay", ['amount' => 500_000, 'account' => '521100'])->assertOk();
        $this->assertSame(0, (int) DB::table('supplier_payments')->sum('withheld'));
        $this->assertSame(0, DB::table('withholding_certificates')->count());

        $this->putJson("/api/v1/accounting3/tax/suppliers/{$this->supplier->id}/profile", ['withholding_code' => 'RAS_SERV', 'exempt' => true])->assertOk();
        $inv2 = $this->approvedInvoice(500_000, 0, 'EXEMPT');
        $this->postJson("/api/v1/payables/invoices/{$inv2['id']}/pay", ['amount' => 500_000, 'account' => '521100'])->assertOk();
        $this->assertSame(0, DB::table('withholding_certificates')->count());

        $this->postJson('/api/v1/accounting3/tax/withholding-rates', ['code' => 'GROS', 'name' => 'Seuil', 'rate' => 10, 'threshold' => 300_000, 'base' => 'gross'])->assertOk();
        $this->putJson("/api/v1/accounting3/tax/suppliers/{$this->supplier->id}/profile", ['withholding_code' => 'GROS', 'exempt' => false])->assertOk();
        $small = $this->approvedInvoice(200_000, 0, 'SMALL');
        $this->postJson("/api/v1/payables/invoices/{$small['id']}/pay", ['amount' => 200_000, 'account' => '521100'])->assertOk();
        $this->assertSame(0, DB::table('withholding_certificates')->count()); // below the threshold
        $big = $this->approvedInvoice(400_000, 0, 'BIG');
        $this->postJson("/api/v1/payables/invoices/{$big['id']}/pay", ['amount' => 400_000, 'account' => '521100'])->assertOk();
        $this->assertSame(40_000, (int) DB::table('withholding_certificates')->value('amount')); // 10% of the gross
        $this->assertBooksBalance();
    }

    public function test_withholding_through_a_payment_run_and_permissions(): void
    {
        $this->putJson("/api/v1/accounting3/tax/suppliers/{$this->supplier->id}/profile", ['withholding_code' => 'RAS_SERV'])->assertOk();
        $inv = $this->approvedInvoice(1_000_000, 0, 'RUN-1');
        $this->postJson('/api/v1/payables/payment-runs', ['invoice_ids' => [$inv['id']], 'account' => '521100'])->assertCreated();
        $this->assertSame(55_000, (int) DB::table('withholding_certificates')->value('amount'));
        $b = $this->assertBooksBalance();
        $this->assertSame(-55_000, $b['447100']);

        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $this->actAs($acc, $this->hotel);
        $this->getJson('/api/v1/accounting3/tax/withholding-certificates')->assertOk();
        $this->postJson('/api/v1/accounting3/tax/withholding-rates', ['code' => 'X', 'name' => 'x', 'rate' => 1])->assertForbidden();
        $this->postJson('/api/v1/accounting3/tax/withholding-certificates/remit', ['ids' => [1], 'date' => now()->toDateString(), 'account' => '521100'])->assertForbidden();
    }

    public function test_stamp_duty_rules_register_and_posting(): void
    {
        $this->postJson('/api/v1/accounting3/tax/stamp-duty/rules', ['code' => 'REC', 'name' => 'Timbre quittance', 'applies_to' => 'cash_receipt', 'kind' => 'percent', 'rate' => 1, 'min_base' => 50_000, 'min_duty' => 100])->assertOk();
        $this->postJson('/api/v1/accounting3/tax/stamp-duty/rules', ['code' => 'CTR', 'name' => 'Timbre contrat', 'applies_to' => 'contract', 'kind' => 'fixed', 'fixed_amount' => 25_000])->assertOk();

        $this->postJson('/api/v1/accounting3/tax/stamp-duty', ['code' => 'REC', 'base' => 2_000_000, 'date' => now()->toDateString(), 'preview' => true])->assertOk()->assertJsonPath('amount', 20_000);
        $this->assertSame(0, DB::table('stamp_duty_entries')->count());
        $r = $this->postJson('/api/v1/accounting3/tax/stamp-duty', ['code' => 'REC', 'base' => 2_000_000, 'date' => now()->toDateString(), 'source_type' => 'receipt', 'source_id' => 'R-1'])->assertCreated()->json();
        $this->assertSame(20_000, $r['amount']);
        $this->postJson('/api/v1/accounting3/tax/stamp-duty', ['code' => 'REC', 'base' => 10_000, 'date' => now()->toDateString()])->assertCreated()->assertJsonPath('amount', 0); // under min_base: nothing booked
        $this->postJson('/api/v1/accounting3/tax/stamp-duty', ['code' => 'CTR', 'base' => 5_000_000, 'date' => now()->toDateString()])->assertCreated()->assertJsonPath('amount', 25_000);
        $this->postJson('/api/v1/accounting3/tax/stamp-duty', ['code' => 'NOPE', 'base' => 1, 'date' => now()->toDateString()])->assertStatus(422);

        $b = $this->assertBooksBalance();
        $this->assertSame(45_000, $b['646100']);
        $this->assertSame(-45_000, $b['447400']);
        $reg = $this->getJson('/api/v1/accounting3/tax/stamp-duty')->assertOk()->json();
        $this->assertCount(2, $reg['register']);
        $this->assertSame(45_000, array_sum(array_column($reg['register'], 'amount')));
    }
}

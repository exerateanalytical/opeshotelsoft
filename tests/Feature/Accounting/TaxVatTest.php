<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxVatTest extends TestCase
{
    private function entry(string $date, array $lines, string $d = 'T'): void
    {
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => $d, 'lines' => $lines])->assertCreated();
    }

    /** Sales of 1,000,000 + 19.25% VAT and purchases of 400,000 + 77,000 VAT in the month of $ym. */
    private function month(string $ym, int $sales = 1_000_000, int $purchases = 400_000): void
    {
        $vat = (int) round($sales * 0.1925);
        $pvat = (int) round($purchases * 0.1925);
        if ($sales) {
            $this->entry("$ym-10", [['account' => '411200', 'debit' => $sales + $vat], ['account' => '706100', 'credit' => $sales], ['account' => '443100', 'credit' => $vat]], 'Ventes');
        }
        if ($purchases) {
            $this->entry("$ym-12", [['account' => '632000', 'debit' => $purchases], ['account' => '445200', 'debit' => $pvat], ['account' => '401100', 'credit' => $purchases + $pvat]], 'Achats');
        }
    }

    public function test_return_is_computed_from_the_ledger_filed_locked_and_settled(): void
    {
        $this->month('2026-10');
        $p = $this->getJson('/api/v1/accounting3/tax/vat-returns/preview?from=2026-10-01&to=2026-10-31')->assertOk()->json();
        $this->assertSame('CM', $p['country']);
        $this->assertSame(19.25, $p['rate']);
        $this->assertSame(192_500, $p['figures']['vat_collected']);
        $this->assertSame(77_000, $p['figures']['vat_deductible']);
        $this->assertSame(115_500, $p['figures']['vat_due']);
        $this->assertSame(1_000_000, $p['figures']['turnover_taxable']);
        $this->assertSame('2026-11-15', $p['filing_deadline']);

        $draft = $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated()->assertJsonPath('status', 'draft')->json();
        // Re-preparing refreshes the same draft.
        $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated()->assertJsonPath('id', $draft['id']);
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/file", [])->assertStatus(422); // reference required

        $filed = $this->postJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/file", ['reference' => 'DGI-2026-10-0042'])->assertOk()->json();
        $this->assertSame('filed', $filed['status']);
        $this->assertTrue($filed['locked']);
        $this->assertNotNull($filed['settlement_entry_id']);

        // Settlement entry: collected and deductible VAT cleared against 444 (VAT due), trial balance still balanced.
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['443100'] ?? 0);
        $this->assertSame(0, $b['445200'] ?? 0);
        $this->assertSame(-115_500, $b['444100']);

        // Locked: no second filing, no re-preparing, no overlapping period.
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/file", ['reference' => 'again'])->assertStatus(422);
        $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertStatus(422);
        $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-10-15', 'to' => '2026-11-02'])->assertStatus(422);
        $this->getJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/verify")->assertOk()->assertJsonPath('matches', true);

        // A late ledger entry in the period is detected, and the amendment posts only the delta.
        $this->entry('2026-10-28', [['account' => '411200', 'debit' => 119_250], ['account' => '706100', 'credit' => 100_000], ['account' => '443100', 'credit' => 19_250]], 'Vente tardive');
        $this->getJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/verify")->assertOk()->assertJsonPath('matches', false);
        $am = $this->postJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/amend")->assertCreated()->json();
        $this->assertSame(2, $am['version']);
        $this->assertSame(134_750, $am['figures']['vat_due']);
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$draft['id']}/amend")->assertStatus(422); // v1 is no longer the filed one? still filed until v2 is filed -> amendment exists
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$am['id']}/file", ['reference' => 'DGI-2026-10-0042-R1'])->assertOk()->assertJsonPath('status', 'filed');
        $b = $this->assertBooksBalance();
        $this->assertSame(-134_750, $b['444100']);
        $this->assertSame(0, $b['443100'] ?? 0);
        $statuses = collect($this->getJson('/api/v1/accounting3/tax/vat-returns')->json())->pluck('status', 'version')->all();
        $this->assertSame(['superseded', 'filed'], [$statuses[1], $statuses[2]]);
    }

    public function test_vat_credit_is_carried_forward_into_the_next_return(): void
    {
        $this->month('2026-08', 0, 400_000); // purchases only: credit of 77,000
        $a = $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-08-01', 'to' => '2026-08-31'])->assertCreated()->json();
        $this->assertSame(77_000, $a['figures']['credit_to_carry']);
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$a['id']}/file", ['reference' => 'AUG'])->assertOk();
        $b = $this->assertBooksBalance();
        $this->assertSame(77_000, $b['445800']);
        $this->assertSame(0, $b['445200'] ?? 0);

        $this->month('2026-09', 500_000, 0); // collected 96,250
        $s = $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-09-01', 'to' => '2026-09-30'])->assertCreated()->json();
        $this->assertSame(77_000, $s['figures']['credit_brought_forward']);
        $this->assertSame(19_250, $s['figures']['vat_due']);
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$s['id']}/file", ['reference' => 'SEP'])->assertOk();
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['445800'] ?? 0);
        $this->assertSame(-19_250, $b['444100']);
    }

    public function test_country_profiles_drive_rate_and_form_and_future_periods_cannot_be_filed(): void
    {
        $this->assertCount(6, $this->getJson('/api/v1/accounting3/tax/profile')->assertOk()->json('countries'));
        $this->putJson('/api/v1/accounting3/tax/profile', ['country' => 'GA'])->assertOk()->assertJsonPath('country', 'GA');
        $this->putJson('/api/v1/accounting3/tax/profile', ['country' => 'ZZ'])->assertStatus(422);
        $this->entry('2026-10-10', [['account' => '411200', 'debit' => 1_180_000], ['account' => '706100', 'credit' => 1_000_000], ['account' => '443100', 'credit' => 180_000]]);
        $p = $this->getJson('/api/v1/accounting3/tax/vat-returns/preview?from=2026-10-01&to=2026-10-31')->json();
        $this->assertEquals(18.0, $p['rate']);
        $this->assertSame(1_000_000, $p['figures']['turnover_taxable']);
        foreach (['CG' => 18.9, 'TD' => 18.0, 'CF' => 19.0, 'GQ' => 15.0, 'CM' => 19.25] as $c => $rate) {
            $this->assertEquals($rate, $this->putJson('/api/v1/accounting3/tax/profile', ['country' => $c])->assertOk()->json('vat_rate'));
        }
        $this->putJson('/api/v1/accounting3/tax/profile', ['country' => 'CM', 'config' => ['vat_rate' => 20]])->assertOk()->assertJsonPath('vat_rate', 20);

        $future = $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()])->assertCreated()->json();
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$future['id']}/file", ['reference' => 'EARLY'])->assertStatus(422);

        // Only a tax filer may file or change the profile; a viewer can read.
        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $this->actAs($acc, $this->hotel);
        $this->getJson('/api/v1/accounting3/tax/vat-returns')->assertOk();
        $this->postJson("/api/v1/accounting3/tax/vat-returns/{$future['id']}/file", ['reference' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/accounting3/tax/profile', ['country' => 'GA'])->assertForbidden();
    }

    public function test_exports_and_tenant_isolation(): void
    {
        $this->month('2026-10');
        $d = $this->postJson('/api/v1/accounting3/tax/vat-returns', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated()->json();
        $csv = $this->get("/api/v1/accounting3/tax/vat-returns/{$d['id']}/export?format=csv")->assertOk();
        $this->assertStringContainsString('115500', $csv->getContent());
        $xlsx = $this->get("/api/v1/accounting3/tax/vat-returns/{$d['id']}/export?format=xlsx")->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        [$g2, $h2] = $this->makeHotel('Autre Groupe', 'OTH');
        $this->provision($h2);
        $u2 = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->actAs($u2, $h2);
        $this->getJson("/api/v1/accounting3/tax/vat-returns/{$d['id']}")->assertStatus(422);
        $this->assertSame([], $this->getJson('/api/v1/accounting3/tax/vat-returns')->json());
        $this->assertSame(1, DB::table('vat_returns')->count());
    }
}

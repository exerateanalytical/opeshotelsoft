<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloseStatementsPackTest extends TestCase
{
    private function entry(string $date, array $lines): void
    {
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => 'T', 'lines' => $lines])->assertCreated();
    }

    private function scenario(): void
    {
        $this->entry('2026-01-02', [['account' => '521100', 'debit' => 5_000_000], ['account' => '101000', 'credit' => 5_000_000]]);          // capital
        $this->entry('2026-01-05', [['account' => '244000', 'debit' => 2_000_000], ['account' => '521100', 'credit' => 2_000_000]]);          // furniture
        $this->entry('2026-03-10', [['account' => '521100', 'debit' => 1_192_500], ['account' => '706100', 'credit' => 1_000_000], ['account' => '443100', 'credit' => 192_500]]); // sales
        $this->entry('2026-03-15', [['account' => '632000', 'debit' => 300_000], ['account' => '521100', 'credit' => 300_000]]);              // fees
        $this->entry('2026-03-31', [['account' => '661100', 'debit' => 400_000], ['account' => '521100', 'credit' => 400_000]]);              // salaries
        $this->entry('2026-03-31', [['account' => '681000', 'debit' => 200_000], ['account' => '284400', 'credit' => 200_000]]);              // depreciation
    }

    public function test_pack_follows_the_syscohada_layout_and_balances(): void
    {
        $this->scenario();
        $p = $this->getJson('/api/v1/accounting3/close/statements?from=2026-01-01&to=2026-12-31')->assertOk()->json();
        $this->assertSame('SYSCOHADA révisé', $p['meta']['layout']);

        $actif = collect($p['bilan']['actif'])->keyBy('ref');
        $this->assertSame(2_000_000, $actif['AM']['brut']);
        $this->assertSame(-200_000, $actif['AM']['amort']);
        $this->assertSame(1_800_000, $actif['AM']['net']);
        $this->assertSame(5_000_000 - 2_000_000 + 1_192_500 - 300_000 - 400_000, $actif['BS']['net']);
        $passif = collect($p['bilan']['passif'])->keyBy('ref');
        $this->assertSame(5_000_000, $passif['CA']['amount']);
        $this->assertSame(192_500, $passif['DK']['amount']);  // output VAT owed
        $this->assertSame(100_000, $passif['CJ']['amount']);  // result of the year: 1,000,000 - 300,000 - 400,000 - 200,000
        $this->assertTrue($p['bilan']['balanced']);
        $this->assertSame($p['bilan']['total_actif'], $p['bilan']['total_passif']);

        $bal = collect($p['resultat']['balances'])->pluck('amount', 'ref');
        $this->assertSame(1_000_000, $bal['XB']);
        $this->assertSame(300_000, collect($p['resultat']['lines'])->firstWhere('ref', 'RH')['amount']);
        $this->assertSame(400_000, collect($p['resultat']['lines'])->firstWhere('ref', 'RK')['amount']);
        $this->assertSame(100_000, $bal['XE']);
        $this->assertSame(100_000, $bal['XI']);
        $this->assertSame(100_000, $p['resultat']['net_result']);
        $this->assertTrue($p['resultat']['check']);

        $this->assertTrue($p['tafire']['check']);
        $lines = collect($p['tafire']['lines'])->pluck('amount', 'label');
        $this->assertSame(300_000, $lines['Capacité d\'autofinancement globale (CAFG)']); // 100,000 result + 200,000 depreciation
        $this->assertSame(5_000_000 - 2_000_000 + 1_192_500 - 700_000, $lines['Trésorerie de clôture']);

        $notes = collect($p['notes'])->keyBy('note');
        $this->assertSame('auto', $notes['3A']['status']);
        $this->assertSame(2_000_000, $notes['3A']['rows'][0]['acquisitions']);
        $this->assertSame('to_complete', $notes['1']['status']);
        $this->assertGreaterThanOrEqual(15, count($p['notes']));
        $this->assertBooksBalance();
    }

    public function test_comparatives_excel_pack_history_and_finalisation(): void
    {
        $this->scenario();
        $this->entry('2025-06-01', [['account' => '521100', 'debit' => 100_000], ['account' => '706100', 'credit' => 100_000]]);
        $p = $this->getJson('/api/v1/accounting3/close/statements?from=2026-01-01&to=2026-12-31')->json();
        $this->assertSame('2025-01-01', $p['meta']['comparative_from']);
        $this->assertSame(100_000, collect($p['resultat']['comparative'])->firstWhere('ref', 'XI')['amount']);
        $this->assertSame(100_000, $p['resultat']['net_result_prev']);

        $x = $this->get('/api/v1/accounting3/close/statements?from=2026-01-01&to=2026-12-31&format=xlsx')->assertOk();
        $this->assertStringStartsWith('PK', $x->getContent());

        $pack = $this->postJson('/api/v1/accounting3/close/statement-packs', ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertCreated()->json();
        $this->assertSame('draft', $pack['status']);
        $this->assertSame(64, strlen($pack['hash']));
        $this->assertSame(100_000, $pack['payload']['resultat']['net_result']);
        $this->assertCount(1, $this->getJson('/api/v1/accounting3/close/statement-packs')->json());
        $this->postJson("/api/v1/accounting3/close/statement-packs/{$pack['id']}/finalize")->assertOk()->assertJsonPath('status', 'final');
        $this->postJson("/api/v1/accounting3/close/statement-packs/{$pack['id']}/finalize")->assertStatus(422);
        $this->assertStringStartsWith('PK', $this->get("/api/v1/accounting3/close/statement-packs/{$pack['id']}?format=xlsx")->getContent());
        $this->getJson('/api/v1/accounting3/close/statements?from=2026-12-31&to=2026-01-01')->assertStatus(422);

        $r = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($r, $this->hotel);
        $this->getJson('/api/v1/accounting3/close/statements?from=2026-01-01&to=2026-12-31')->assertForbidden();
        $this->assertSame(1, DB::table('statement_packs')->count());
    }
}

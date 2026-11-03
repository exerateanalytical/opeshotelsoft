<?php

namespace Tests\Feature\Accounting;

use App\Modules\Tenancy\Domain\Hotel;
use Tests\TestCase;

class CoreGroupConsolidationTest extends TestCase
{
    private Hotel $second;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->second] = $this->makeHotel('ignored', 'BBB', $this->group);
        $this->provision($this->second);
    }

    private function on(Hotel $h): void
    {
        $this->actAs($this->user, $h);
    }

    private function sale(string $date, int $amount): void
    {
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => 'Vente', 'lines' => [
            ['account' => '521100', 'debit' => $amount], ['account' => '706100', 'credit' => $amount]]])->assertCreated();
    }

    private function consolidate(string $q = ''): array
    {
        return $this->getJson('/api/v1/accounting/core/consolidation?from=2026-03-01&to=2026-03-31'.$q)->assertOk()->json();
    }

    public function test_intercompany_services_post_mirror_entries_and_are_eliminated(): void
    {
        $this->sale('2026-03-05', 1_000_000);
        $this->on($this->second);
        $this->sale('2026-03-06', 400_000);
        $this->on($this->hotel);

        // Hotel A provides a 100,000 service to hotel B (laundry): A revenue 706800, B expense 658000.
        $ic = $this->postJson('/api/v1/accounting/core/intercompany', [
            'from_hotel_id' => $this->hotel->id, 'to_hotel_id' => $this->second->id, 'kind' => 'service', 'date' => '2026-03-10', 'amount' => 100_000, 'description' => 'Blanchisserie',
        ])->assertCreated()->json();
        $this->assertNotNull($ic['from_entry_id']);
        $this->assertNotNull($ic['to_entry_id']);
        $bal = $this->assertBooksBalance();
        $this->assertSame(100_000, $bal['451100']);
        $this->on($this->second);
        $this->assertSame(-100_000, $this->assertBooksBalance()['451200']);
        $this->on($this->hotel);

        $c = $this->consolidate();
        $this->assertTrue($c['trial_balance']['balanced']);
        $this->assertSame(1_400_000 + 100_000 - 100_000, $c['income_statement']['total_revenue']); // 1.0M + 0.4M + IC revenue 0.1M - eliminated 0.1M
        $this->assertSame(0, $c['income_statement']['total_expenses']);
        $this->assertSame(1_400_000, $c['income_statement']['result']);
        $this->assertSame(0, $c['eliminations']['ic_difference']);
        $this->assertSame(100_000, $c['eliminations']['intercompany_services']);
        $this->assertSame($c['balance_sheet']['total_assets'], $c['balance_sheet']['total_liabilities_equity']);
        $this->assertCount(2, $c['hotels']);
        // Without elimination the hotels alone would sum to revenue 1.5M and expense 0.1M; the group result is unchanged at 1.4M.
        $this->assertSame(1_500_000, array_sum(array_column($c['hotels'], 'revenue')));
    }

    public function test_funding_and_settlement_clear_current_accounts_and_a_break_is_flagged(): void
    {
        $this->sale('2026-03-05', 1_000_000);
        $this->postJson('/api/v1/accounting/core/intercompany', ['from_hotel_id' => $this->hotel->id, 'to_hotel_id' => $this->second->id, 'kind' => 'funding', 'date' => '2026-03-08', 'amount' => 300_000, 'description' => 'Pret de tresorerie'])->assertCreated();
        $s = $this->postJson('/api/v1/accounting/core/intercompany', ['from_hotel_id' => $this->hotel->id, 'to_hotel_id' => $this->second->id, 'kind' => 'settlement', 'date' => '2026-03-20', 'amount' => 100_000, 'description' => 'Remboursement partiel'])->assertCreated()->json();
        $this->assertSame(200_000, $this->assertBooksBalance()['451100']);
        $c = $this->consolidate();
        $this->assertSame(0, $c['eliminations']['ic_difference']);
        $this->assertSame(200_000, $c['eliminations']['receivable']);
        $this->assertTrue($c['trial_balance']['balanced']);
        $this->assertSame($c['balance_sheet']['total_assets'], $c['balance_sheet']['total_liabilities_equity']);

        // A one-sided posting in the current account (not through the service) shows up as a break, TB still balances.
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => '2026-03-25', 'description' => 'Saisie unilaterale', 'lines' => [['account' => '451100', 'debit' => 50_000], ['account' => '706800', 'credit' => 50_000]]])->assertCreated();
        $c = $this->consolidate();
        $this->assertSame(50_000, $c['eliminations']['ic_difference']);
        $this->assertTrue($c['trial_balance']['balanced']);

        // Reversal keeps both books balanced and removes the settlement effect.
        $this->postJson("/api/v1/accounting/core/intercompany/{$s['id']}/reverse", ['reason' => 'annule'])->assertCreated()->assertJsonPath('status', 'reversed');
        $this->assertSame(300_000 + 50_000, $this->assertBooksBalance()['451100']);
    }

    public function test_presentation_currency_translation_balances_with_cta(): void
    {
        $this->sale('2026-03-05', 655_957_000);
        $c = $this->consolidate('&currency=EUR&closing_rate=655.957&average_rate=650');
        $this->assertSame('EUR', $c['currency']);
        $this->assertEquals(1_000_000.0, $c['balance_sheet']['total_assets']);
        $this->assertEquals(round(655_957_000 / 650, 2), $c['income_statement']['total_revenue']);
        $bs = $c['balance_sheet'];
        $this->assertEqualsWithDelta($bs['total_assets'], $bs['total_liabilities_equity'], 0.011);
        $this->assertNotEquals(0, $bs['cta']); // closing vs average rate difference lands in equity
        $this->getJson('/api/v1/accounting/core/consolidation?from=2026-03-01&to=2026-03-31&currency=USD')->assertStatus(422); // no rate
    }

    public function test_intercompany_across_groups_and_consolidation_are_refused_or_isolated(): void
    {
        [, $foreign] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($foreign);
        $this->postJson('/api/v1/accounting/core/intercompany', ['from_hotel_id' => $this->hotel->id, 'to_hotel_id' => $foreign->id, 'kind' => 'funding', 'date' => '2026-03-08', 'amount' => 1000, 'description' => 'x'])->assertStatus(422);
        $this->assertCount(2, $this->consolidate()['hotels']);

        $single = $this->makeUser($this->group, 'accountant', hotels: [$this->hotel]);
        $this->actAs($single, $this->hotel);
        $this->getJson('/api/v1/accounting/core/consolidation?from=2026-03-01&to=2026-03-31')->assertForbidden();
    }

    public function test_group_budget_summary_lists_each_hotel(): void
    {
        foreach ([$this->hotel, $this->second] as $h) {
            $this->on($h);
            $b = $this->postJson('/api/v1/accounting/core/budgets', ['name' => 'B26', 'year' => 2026, 'lines' => [['account' => '706100', 'month' => 1, 'amount' => 500_000]]])->assertCreated()->json();
            $this->postJson("/api/v1/accounting/core/budgets/{$b['id']}/approve")->assertOk();
        }
        $this->on($this->hotel);
        $g = $this->getJson('/api/v1/accounting/core/budgets/group?year=2026')->assertOk()->json();
        $this->assertCount(2, $g['hotels']);
    }
}

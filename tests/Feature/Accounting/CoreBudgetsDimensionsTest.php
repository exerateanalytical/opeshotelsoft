<?php

namespace Tests\Feature\Accounting;

use Tests\TestCase;

class CoreBudgetsDimensionsTest extends TestCase
{
    private function entry(string $date, array $lines, string $desc = 'Test'): array
    {
        return $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => $desc, 'lines' => $lines])->assertCreated()->json();
    }

    private function budgetLines(): array
    {
        $lines = [];
        foreach ([1, 2, 3, 4] as $m) {
            $lines[] = ['account' => '706100', 'department' => 'rooms', 'month' => $m, 'amount' => 1_000_000];
            $lines[] = ['account' => '605200', 'department' => 'rooms', 'month' => $m, 'amount' => 100_000];
        }

        return $lines;
    }

    public function test_budget_variance_forecast_and_freeze(): void
    {
        $b = $this->postJson('/api/v1/accounting/core/budgets', ['name' => 'Budget 2026', 'year' => 2026, 'lines' => $this->budgetLines()])->assertCreated()->json();
        // January: revenue 1.2M (favourable +200k), electricity 90k (favourable +10k). February: revenue 800k, electricity 150k.
        $this->entry('2026-01-15', [['account' => '521100', 'debit' => 1_200_000], ['account' => '706100', 'credit' => 1_200_000, 'department' => 'rooms']]);
        $this->entry('2026-01-20', [['account' => '605200', 'debit' => 90_000, 'department' => 'rooms'], ['account' => '521100', 'credit' => 90_000]]);
        $this->entry('2026-02-15', [['account' => '521100', 'debit' => 800_000], ['account' => '706100', 'credit' => 800_000, 'department' => 'rooms']]);
        $this->entry('2026-02-20', [['account' => '605200', 'debit' => 150_000, 'department' => 'rooms'], ['account' => '521100', 'credit' => 150_000]]);

        $this->postJson("/api/v1/accounting/core/budgets/{$b['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        // An approved budget is frozen.
        $this->postJson('/api/v1/accounting/core/budgets', ['id' => $b['id'], 'name' => 'Budget 2026', 'year' => 2026, 'lines' => $this->budgetLines()])->assertStatus(422);

        $v = $this->getJson("/api/v1/accounting/core/budgets/{$b['id']}/variance?through=2")->assertOk()->json();
        $rows = collect($v['rows'])->keyBy('account');
        $this->assertSame(2_000_000, $rows['706100']['budget_ytd']);
        $this->assertSame(2_000_000, $rows['706100']['actual_ytd']);
        $this->assertSame(0, $rows['706100']['variance']);
        $this->assertSame(200_000, $rows['605200']['budget_ytd']);
        $this->assertSame(240_000, $rows['605200']['actual_ytd']);
        $this->assertSame(-40_000, $rows['605200']['variance']); // expense overspend is unfavourable
        // Forecast = actual YTD + budget of the remaining months.
        $this->assertSame(2_000_000 + 2_000_000, $rows['706100']['forecast']);
        $this->assertSame(240_000 + 200_000, $rows['605200']['forecast']);
        $this->assertSame(1_760_000, $v['totals']['result']['actual']);

        $byDept = $this->getJson("/api/v1/accounting/core/budgets/{$b['id']}/variance?through=2&by=department")->assertOk()->json();
        $this->assertSame(1_760_000, $byDept['rows'][0]['actual_ytd']);
        $this->assertSame(1_800_000, $byDept['rows'][0]['budget_ytd']);

        $rr = $this->getJson("/api/v1/accounting/core/budgets/{$b['id']}/variance?through=2&method=run_rate")->assertOk()->json();
        $this->assertSame(12_000_000, collect($rr['rows'])->firstWhere('account', '706100')['forecast']);

        $this->assertBooksBalance();
    }

    public function test_budget_rejects_balance_sheet_accounts_and_needs_permission(): void
    {
        $this->postJson('/api/v1/accounting/core/budgets', ['name' => 'Bad', 'year' => 2026, 'lines' => [['account' => '521100', 'month' => 1, 'amount' => 10]]])->assertStatus(422);
        $acc = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($acc, $this->hotel);
        $this->postJson('/api/v1/accounting/core/budgets', ['name' => 'X', 'year' => 2026, 'lines' => [['account' => '706100', 'month' => 1, 'amount' => 10]]])->assertForbidden();
    }

    public function test_dimension_defaults_flow_to_lines_and_reports_by_dimension(): void
    {
        $this->postJson('/api/v1/accounting/core/dimensions', ['kind' => 'outlet', 'code' => 'BAR1', 'name' => 'Bar piscine'])->assertCreated();
        $this->postJson('/api/v1/accounting/core/dimensions', ['kind' => 'project', 'code' => 'REFIT', 'name' => 'Renovation'])->assertCreated();
        $this->postJson('/api/v1/accounting/core/dimension-defaults', ['department' => 'beverage', 'outlet' => 'BAR1', 'cost_centre' => 'CC-FNB'])->assertCreated();

        // Department only: outlet and cost centre come from the defaults. Explicit values win.
        $e = $this->entry('2026-03-10', [['account' => '521100', 'debit' => 500_000], ['account' => '706300', 'credit' => 500_000, 'department' => 'beverage']]);
        $line = collect($e['lines'])->firstWhere('account_code', '706300');
        $this->assertSame('BAR1', $line['outlet']);
        $this->assertSame('CC-FNB', $line['cost_centre']);
        $this->entry('2026-03-11', [['account' => '624000', 'debit' => 120_000, 'department' => 'beverage', 'outlet' => 'BAR2', 'project' => 'REFIT'], ['account' => '521100', 'credit' => 120_000]]);
        $this->entry('2026-03-12', [['account' => '624000', 'debit' => 30_000], ['account' => '521100', 'credit' => 30_000]]);

        $r = $this->getJson('/api/v1/accounting/core/reports/dimension?by=outlet&from=2026-03-01&to=2026-03-31')->assertOk()->json();
        $rows = collect($r['rows'])->keyBy('code');
        $this->assertSame(500_000, $rows['BAR1']['revenue']);
        $this->assertSame(120_000, $rows['BAR2']['expenses']);
        $this->assertSame(30_000, $rows['']['expenses']); // unassigned
        $this->assertSame('Bar piscine', $rows['BAR1']['name']);

        $p = $this->getJson('/api/v1/accounting/core/reports/dimension?by=project&from=2026-03-01&to=2026-03-31')->json();
        $this->assertSame(120_000, collect($p['rows'])->firstWhere('code', 'REFIT')['expenses']);

        // Reversal keeps the dimensions.
        $this->postJson("/api/v1/accounting/core/entries/{$e['id']}/reverse", ['reason' => 'test'])->assertCreated();
        $r = $this->getJson('/api/v1/accounting/core/reports/dimension?by=outlet&from=2026-03-01&to=2026-03-31')->json();
        $this->assertSame(0, collect($r['rows'])->firstWhere('code', 'BAR1')['revenue'] ?? 0);
        $this->assertBooksBalance();
    }
}

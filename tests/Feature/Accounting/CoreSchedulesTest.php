<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreSchedulesTest extends TestCase
{
    private function book(string $date, array $lines): array
    {
        return $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => 'T', 'lines' => $lines])->assertCreated()->json();
    }

    public function test_prepayment_schedule_spreads_with_remainder_and_posts_due_items_only(): void
    {
        // Insurance paid up front: 10,000 over 3 months, booked to 476 by the normal flow.
        $s = $this->postJson('/api/v1/accounting/core/schedules', [
            'kind' => 'prepaid_expense', 'description' => 'Assurance T1', 'debit_account' => '622000', 'total' => 10_000, 'start_date' => '2026-01-01', 'months' => 3,
            'department' => 'admin', 'cost_centre' => 'CC-ADM',
        ])->assertCreated()->json();
        $this->assertSame([3333, 3333, 3334], array_column($s['items'], 'amount'));
        $this->book('2026-01-01', [['account' => '476000', 'debit' => 10_000], ['account' => '521100', 'credit' => 10_000]]);

        $run = $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-02-28'])->assertOk()->json();
        $this->assertSame(2, $run['deferrals']['posted']);
        $again = $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-02-28'])->json();
        $this->assertSame(0, $again['deferrals']['posted']);

        $bal = $this->assertBooksBalance();
        $this->assertSame(6_666, $bal['622000']);
        $this->assertSame(10_000 - 6_666, $bal['476000']);
        $this->assertSame('CC-ADM', DB::table('journal_lines')->where('account_code', '622000')->value('cost_centre'));

        $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-03-31'])->assertOk();
        $this->assertSame(0, $this->assertBooksBalance()['476000']);
    }

    public function test_closed_period_item_fails_without_blocking_and_retries_after_reopen(): void
    {
        $this->postJson('/api/v1/accounting/core/schedules', ['kind' => 'deferred_income', 'description' => 'Abonnement', 'credit_account' => '706800', 'total' => 6_000, 'start_date' => '2026-01-01', 'months' => 2])->assertCreated();
        $this->postJson('/api/v1/accounting/periods/close', ['year' => 2026, 'month' => 1])->assertOk();
        $run = $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-02-28'])->json();
        $this->assertSame(1, $run['deferrals']['posted']);
        $this->assertSame(1, $run['deferrals']['failed']);
        DB::table('fiscal_periods')->where(['year' => 2026, 'month' => 1])->update(['status' => 'open']);
        $this->assertSame(1, $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-02-28'])->json('deferrals.posted'));
        $this->assertBooksBalance();
    }

    public function test_accrual_posts_at_month_end_and_reverses_next_month(): void
    {
        $this->postJson('/api/v1/accounting/core/schedules', ['kind' => 'accrued_expense', 'description' => 'Electricite janvier', 'debit_account' => '605200', 'total' => 80_000, 'start_date' => '2026-01-31'])->assertCreated();
        $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-01-31']);
        $bal = $this->assertBooksBalance();
        $this->assertSame(80_000, $bal['605200']);
        $this->assertSame(-80_000, $bal['408100']);
        $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-02-01']);
        $bal = $this->assertBooksBalance();
        $this->assertSame(0, $bal['605200']);
        $this->assertSame(0, $bal['408100']);
    }

    public function test_recurring_journal_catches_up_once_and_stays_balanced(): void
    {
        $this->postJson('/api/v1/accounting/core/recurring', [
            'name' => 'Loyer', 'journal' => 'OD', 'frequency' => 'monthly', 'next_date' => '2026-01-05', 'end_date' => '2026-04-05',
            'lines' => [['account' => '622000', 'debit' => 250_000, 'cost_centre' => 'CC-ADM'], ['account' => '521100', 'credit' => 250_000]],
        ])->assertCreated();
        $this->postJson('/api/v1/accounting/core/recurring', ['name' => 'Bad', 'journal' => 'OD', 'frequency' => 'monthly', 'next_date' => '2026-01-05', 'lines' => [['account' => '622000', 'debit' => 1], ['account' => '521100', 'credit' => 2]]])->assertStatus(422);

        $this->assertSame(3, $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-03-10'])->json('recurring.posted'));
        $this->assertSame(0, $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-03-10'])->json('recurring.posted'));
        $this->assertSame(750_000, $this->assertBooksBalance()['622000']);
        $this->assertSame(1, $this->postJson('/api/v1/accounting/core/schedules/run', ['as_of' => '2026-12-31'])->json('recurring.posted')); // stops at end_date
    }

    public function test_scheduled_command_runs_deferrals_recurring_and_month_end_fx(): void
    {
        $this->postJson('/api/v1/accounting/core/recurring', [
            'name' => 'Loyer', 'journal' => 'OD', 'frequency' => 'monthly', 'next_date' => '2026-05-02',
            'lines' => [['account' => '622000', 'debit' => 100], ['account' => '521100', 'credit' => 100]],
        ])->assertCreated();
        // USD receivable: 100 USD booked at 600 = 60,000 XAF.
        $this->book('2026-04-10', [
            ['account' => '411200', 'debit' => 60_000, 'currency' => 'USD', 'fx_amount' => 100, 'fx_rate' => 600, 'partner_type' => 'company', 'partner_id' => 1],
            ['account' => '706800', 'credit' => 60_000],
        ]);
        DB::table('exchange_rates')->insert(['from' => 'USD', 'to' => 'XAF', 'date' => '2026-04-30', 'rate' => 620]);

        $this->artisan('accounting:run-scheduled', ['--date' => '2026-05-03'])->assertSuccessful();
        $bal = $this->assertBooksBalance();
        $this->assertSame(62_000, $bal['411200']);
        $this->assertSame(-2_000, $bal['776000']);
        $this->assertSame(100, $bal['622000']);
        // Idempotent.
        $this->artisan('accounting:run-scheduled', ['--date' => '2026-05-04'])->assertSuccessful();
        $this->assertSame(62_000, $this->assertBooksBalance()['411200']);
        $this->assertSame(1, DB::table('fx_revaluations')->count());
    }

    public function test_foreign_currency_bank_is_revalued_and_missing_rate_is_skipped_and_audited(): void
    {
        $this->book('2026-01-10', [
            ['account' => '521100', 'debit' => 600_000, 'currency' => 'JPY', 'fx_amount' => 1_000, 'fx_rate' => 600],
            ['account' => '706800', 'credit' => 600_000],
        ]);
        $this->postJson('/api/v1/accounting/core/fx/month-end', ['date' => '2026-01-31'])->assertStatus(422);
        $this->assertTrue(DB::table('accounting_audit_log')->where('action', 'fx_revaluation_skipped')->exists());

        DB::table('exchange_rates')->insert(['from' => 'JPY', 'to' => 'XAF', 'date' => '2026-01-31', 'rate' => 655.957]);
        $this->postJson('/api/v1/accounting/core/fx/month-end', ['date' => '2026-01-31'])->assertOk()->assertJsonPath('status', 'run');
        $this->assertSame(655_957, $this->assertBooksBalance()['521100']);
        $this->postJson('/api/v1/accounting/core/fx/month-end', ['date' => '2026-01-31'])->assertOk()->assertJsonPath('status', 'already-run');
        $exposure = $this->getJson('/api/v1/accounting/core/fx/exposure?date=2026-01-31')->assertOk()->json();
        $this->assertSame(0, $exposure['gain']);
    }
}

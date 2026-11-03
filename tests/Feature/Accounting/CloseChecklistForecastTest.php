<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Guests\Domain\Company;
use App\Modules\HR\Application\Payroll\CameroonRulePack;
use App\Modules\Stock\Domain\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloseChecklistForecastTest extends TestCase
{
    private function entry(string $date, array $lines): void
    {
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => 'T', 'lines' => $lines])->assertCreated();
    }

    public function test_checklist_owners_signoff_checks_and_lock_when_complete(): void
    {
        $this->entry('2026-10-10', [['account' => '521100', 'debit' => 10_000], ['account' => '706800', 'credit' => 10_000]]);
        $run = $this->postJson('/api/v1/accounting3/close/runs', ['year' => 2026, 'month' => 10])->assertCreated()->json();
        $this->postJson('/api/v1/accounting3/close/runs', ['year' => 2026, 'month' => 10])->assertStatus(422);
        $this->assertCount(10, $run['tasks']);
        $this->assertSame('2026-11-03', $run['tasks'][0]['due_date']); // month end + 3 days
        $this->assertSame(0, $run['progress']['done']);

        // An unmatched bank statement line fails the reconciliation check.
        $bank = BankAccount::create(['name' => 'B', 'kind' => 'bank', 'gl_account' => '521100']);
        DB::table('bank_statement_lines')->insert(['bank_account_id' => $bank->id, 'date' => '2026-10-20', 'description' => 'Inconnu', 'amount' => 5000, 'created_at' => now(), 'updated_at' => now()]);
        $tasks = collect($run['tasks'])->keyBy('auto_check');
        $this->postJson("/api/v1/accounting3/close/tasks/{$tasks['bank_reconciled']['id']}/check")->assertOk()->assertJsonPath('ok', false);
        $this->postJson("/api/v1/accounting3/close/tasks/{$tasks['bank_reconciled']['id']}/sign", [])->assertStatus(422);
        DB::table('bank_statement_lines')->update(['reconciled_at' => now(), 'journal_line_id' => DB::table('journal_lines')->value('id')]);
        $this->postJson("/api/v1/accounting3/close/tasks/{$tasks['bank_reconciled']['id']}/sign", ['note' => 'Rapproché'])->assertOk()->assertJsonPath('progress.done', 1);
        $this->postJson("/api/v1/accounting3/close/tasks/{$tasks['bank_reconciled']['id']}/sign", [])->assertStatus(422); // already signed

        // A role owner can sign their own tasks and nobody else's; a close manager can sign for anyone.
        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $payroll = $this->makeUser($this->group, 'payroll-officer', allHotels: true);
        $fdTask = collect($run['tasks'])->first(fn ($t) => $t['owner_role'] === 'finance-director' && ! $t['auto_check'] && str_contains($t['title'], 'Revue des relances'));
        $this->actAs($acc, $this->hotel);
        $this->postJson("/api/v1/accounting3/close/tasks/{$fdTask['id']}/sign", [])->assertForbidden();
        $this->postJson("/api/v1/accounting3/close/tasks/{$tasks['ap_pending']['id']}/sign", [])->assertOk(); // accountant role
        $this->postJson("/api/v1/accounting3/close/tasks/{$tasks['trial_balance']['id']}/assign", ['owner_id' => $payroll->id])->assertForbidden();
        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/accounting3/close/tasks/{$fdTask['id']}/assign", ['owner_id' => $payroll->id])->assertOk();
        $this->actAs($acc, $this->hotel);
        $this->postJson("/api/v1/accounting3/close/tasks/{$fdTask['id']}/sign", [])->assertForbidden(); // owner is now the payroll officer
        $this->actAs($payroll, $this->hotel);
        $this->postJson("/api/v1/accounting3/close/tasks/{$fdTask['id']}/sign", [])->assertOk();

        // The manager signs the rest; the VAT check fails (nothing filed) and needs an override reason.
        $this->actAs($this->user, $this->hotel);
        foreach ($run['tasks'] as $t) {
            if (in_array($t['id'], [$tasks['bank_reconciled']['id'], $tasks['ap_pending']['id'], $fdTask['id']], true) || $t['auto_check'] === 'vat_filed') {
                continue;
            }
            $this->postJson("/api/v1/accounting3/close/tasks/{$t['id']}/sign", [])->assertOk();
        }
        $vat = $tasks['vat_filed']['id'];
        $this->postJson("/api/v1/accounting3/close/tasks/$vat/sign", [])->assertStatus(422);
        $final = $this->postJson("/api/v1/accounting3/close/tasks/$vat/sign", ['override_reason' => 'Hotel non assujetti ce mois'])->assertOk()->json();
        $this->assertSame('complete', $final['status']);
        $this->assertNotNull($final['lock_request']);
        $this->assertSame('pending', $final['lock_request']['status']);

        // Completing only raises the four-eyes lock request; a different approver locks the period.
        $this->entry('2026-10-20', [['account' => '521100', 'debit' => 1], ['account' => '706800', 'credit' => 1]]); // still open
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/accounting/core/period-locks/{$final['lock_request']['id']}/decide", ['approve' => true])->assertOk();
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => '2026-10-21', 'description' => 'late', 'lines' => [['account' => '521100', 'debit' => 1], ['account' => '706800', 'credit' => 1]]])->assertStatus(422);

        // A complete close accepts no more reassignments.
        $this->postJson("/api/v1/accounting3/close/tasks/{$fdTask['id']}/assign", ['owner_id' => $fd->id])->assertStatus(422);
        $audit = array_column($this->getJson('/api/v1/accounting/core/audit-log?subject_type=close_task')->json(), 'action');
        $this->assertContains('close_task_signed', $audit);
        $this->assertBooksBalance();
    }

    public function test_templates_are_configurable_and_a_skipped_optional_task_does_not_block(): void
    {
        $t = $this->getJson('/api/v1/accounting3/close/templates')->assertOk()->json();
        $this->assertCount(10, $t['templates']);
        $this->assertContains('trial_balance', $t['checks']);
        $this->postJson('/api/v1/accounting3/close/templates', ['title' => 'Inventaire physique des stocks', 'owner_role' => 'storekeeper', 'due_offset_days' => 2, 'required' => false, 'auto_check' => 'nope'])->assertStatus(422);
        $new = $this->postJson('/api/v1/accounting3/close/templates', ['title' => 'Inventaire physique des stocks', 'owner_role' => 'storekeeper', 'due_offset_days' => 2, 'required' => false])->assertCreated()->json();
        foreach ($t['templates'] as $tpl) {
            $this->putJson("/api/v1/accounting3/close/templates/{$tpl['id']}", ['title' => $tpl['title'], 'active' => false])->assertOk();
        }
        $run = $this->postJson('/api/v1/accounting3/close/runs', ['year' => 2026, 'month' => 9])->assertCreated()->json();
        $this->assertCount(1, $run['tasks']);
        $this->assertFalse($run['tasks'][0]['required']);
        $this->assertSame($new['id'], $run['tasks'][0]['template_id']);
        $this->assertSame(0, $run['progress']['open_required']);
        $this->assertCount(1, $this->getJson('/api/v1/accounting3/close/runs')->json());
        $this->getJson('/api/v1/accounting3/close/runs/2026/9')->assertOk()->assertJsonPath('tasks.0.overdue', true);
        $this->getJson('/api/v1/accounting3/close/runs/2026/8')->assertStatus(422);
    }

    public function test_thirteen_week_forecast_combines_receivables_payables_payroll_and_recurring(): void
    {
        $start = now()->startOfDay();
        $this->entry($start->copy()->subDay()->toDateString(), [['account' => '521100', 'debit' => 1_000_000], ['account' => '101000', 'credit' => 1_000_000]]);

        $co = Company::create(['name' => 'Alpha', 'city_ledger' => true]);
        $inv = fn (string $n, int $total, int $dueIn, int $paid = 0) => DB::table('invoices')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => $n, 'kind' => 'invoice', 'company_id' => $co->id,
            'bill_to_name' => 'Alpha', 'issue_date' => $start->copy()->subDays(20)->toDateString(), 'due_date' => $start->copy()->addDays($dueIn)->toDateString(), 'currency' => 'XAF', 'total' => $total, 'tax_total' => 0,
            'paid' => $paid, 'lines' => '[]', 'taxes' => '[]', 'status' => $paid ? 'partially_paid' : 'issued', 'created_at' => now(), 'updated_at' => now()]);
        $inv('R-OVERDUE', 200_000, -15);      // overdue: lands in week 1
        $inv('R-W2', 300_000, 9, 100_000);   // week 2, balance 200,000
        $inv('R-FAR', 999_999, 200);         // beyond 13 weeks: ignored

        $s = Supplier::create(['code' => 'F', 'name' => 'Fournisseur']);
        $sinv = fn (string $ref, int $net, int $dueIn, string $status) => DB::table('supplier_invoices')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'supplier_id' => $s->id, 'reference' => $ref,
            'date' => $start->toDateString(), 'due_date' => $start->copy()->addDays($dueIn)->toDateString(), 'net_total' => $net, 'tax_total' => 0, 'total' => $net, 'paid' => 0, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $sinv('P-W3', 150_000, 16, 'approved');
        $sinv('P-PENDING', 80_000, 24, 'pending');

        DB::table('recurring_journals')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'name' => 'Loyer', 'journal' => 'OD', 'frequency' => 'monthly', 'next_date' => $start->copy()->addDays(30)->toDateString(),
            'lines' => json_encode([['account' => '521100', 'credit' => 50_000], ['account' => '632000', 'debit' => 50_000]]), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $f = $this->getJson('/api/v1/accounting3/close/forecast')->assertOk()->json();
        $this->assertCount(13, $f['weeks']);
        $this->assertSame(1_000_000, $f['opening']);
        $w = $f['weeks'];
        $this->assertSame(200_000, $w[0]['in']['receivables']);
        $this->assertSame(200_000, $w[1]['in']['receivables']);
        $this->assertSame(150_000, $w[2]['out']['payables']);
        $this->assertSame(80_000, $w[3]['out']['payables_pending']);
        $this->assertSame(50_000, $w[4]['out']['recurring_out'] + $w[5]['out']['recurring_out']); // first occurrence around day 30
        $this->assertSame(80_000, $f['pending_approval_payables']);
        $this->assertSame(200_000, $f['overdue_receivables_in_week_1']);
        $this->assertSame($w[0]['closing'], $w[1]['opening']);
        $inflows = array_sum(array_column($w, 'total_in'));
        $outflows = array_sum(array_column($w, 'total_out'));
        $this->assertSame(1_000_000 + $inflows - $outflows, $f['closing']);
        $this->assertSame(400_000, $inflows);

        // Options: collection haircut and excluding invoices still pending approval.
        $f2 = $this->getJson('/api/v1/accounting3/close/forecast?collection_pct=50&include_pending=0&weeks=4')->assertOk()->json();
        $this->assertCount(4, $f2['weeks']);
        $this->assertSame(100_000, $f2['weeks'][0]['in']['receivables']);
        $this->assertSame(0, $f2['weeks'][3]['out']['payables_pending']);

        // Payroll: net at month end and the social/tax remittance on the 15th, from the latest approved run.
        $pack = DB::table('payroll_rule_packs')->insertGetId(['country' => 'CM', 'version' => 't', 'effective_from' => '2026-01-01', 'params' => json_encode(CameroonRulePack::params()), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payroll_runs')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'period' => $start->copy()->subMonth()->format('Y-m'), 'payroll_rule_pack_id' => $pack, 'status' => 'paid',
            'gross' => 900_000, 'net' => 700_000, 'employee_social' => 20_000, 'employer_social' => 40_000, 'taxes_withheld' => 50_000, 'other_employer' => 10_000, 'created_at' => now(), 'updated_at' => now()]);
        $f3 = $this->getJson('/api/v1/accounting3/close/forecast')->json();
        $end = $start->copy()->addDays(13 * 7 - 1);
        $pays = $taxes = 0;
        for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonthNoOverflow()) {
            $pays += $m->copy()->endOfMonth()->startOfDay()->between($start, $end) ? 1 : 0;
            $taxes += $m->copy()->addMonthNoOverflow()->startOfMonth()->addDays(14)->between($start, $end) ? 1 : 0;
        }
        $this->assertGreaterThan(0, $pays);
        $this->assertSame($pays * 700_000, array_sum(array_column(array_column($f3['weeks'], 'out'), 'payroll')));
        $this->assertSame($taxes * 120_000, array_sum(array_column(array_column($f3['weeks'], 'out'), 'payroll_taxes')));

        // Permissioned; the forecast never posts anything.
        $r = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($r, $this->hotel);
        $this->getJson('/api/v1/accounting3/close/forecast')->assertForbidden();
        $this->actAs($this->user, $this->hotel);
        $this->assertBooksBalance();
    }
}

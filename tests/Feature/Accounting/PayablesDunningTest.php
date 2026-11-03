<?php

namespace Tests\Feature\Accounting;

use App\Modules\Guests\Domain\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayablesDunningTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Société Alpha', 'email' => 'compta@alpha.cm', 'phone' => '+237690000000', 'city_ledger' => true]);
    }

    private function invoice(string $number, int $total, int $daysOverdue, int $paid = 0, ?int $companyId = null): int
    {
        return DB::table('invoices')->insertGetId([
            'group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => $number, 'kind' => 'invoice', 'company_id' => $companyId ?? $this->company->id, 'bill_to_name' => 'Société Alpha',
            'issue_date' => now()->subDays($daysOverdue + 30)->toDateString(), 'due_date' => now()->subDays($daysOverdue)->toDateString(), 'currency' => 'XAF', 'total' => $total, 'tax_total' => 0, 'paid' => $paid,
            'lines' => '[]', 'taxes' => '[]', 'status' => $paid ? 'partially_paid' : 'issued', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_levels_escalate_with_fees_and_each_level_is_sent_once_by_email_and_whatsapp(): void
    {
        $a = $this->invoice('INV-A', 500_000, 10);          // level 1 (7 days)
        $b = $this->invoice('INV-B', 300_000, 25, 100_000);   // level 2 (21 days), balance 200k
        $c = $this->invoice('INV-C', 100_000, 3);            // not yet overdue enough

        $settings = $this->getJson('/api/v1/accounting3/dunning/settings')->assertOk()->json();
        $this->assertSame([7, 21, 45], array_column($settings['levels'], 'days_overdue'));
        $cands = collect($this->getJson('/api/v1/accounting3/dunning/candidates')->assertOk()->json())->keyBy('invoice_id');
        $this->assertSame(1, $cands[$a]['next_level']);
        $this->assertSame(2, $cands[$b]['next_level']);
        $this->assertSame(5_000, $cands[$b]['fee']);
        $this->assertSame(200_000, $cands[$b]['balance']);
        $this->assertNull($cands[$c]['next_level']);
        $this->assertFalse($cands[$c]['eligible']);

        $sent = $this->postJson('/api/v1/accounting3/dunning/send', [])->assertOk()->json();
        $this->assertCount(2, array_filter($sent, fn ($s) => ($s['status'] ?? null) === 'sent'));
        // One email and one WhatsApp message each, through the Notifications outbox.
        $this->assertSame(4, DB::table('notification_outbox')->where('event', 'dunning.notice')->count());
        $this->assertEqualsCanonicalizing(['email', 'whatsapp'], DB::table('notification_outbox')->where('event', 'dunning.notice')->where('ref_id', $a)->pluck('channel')->all());
        $body = DB::table('dunning_notices')->where('invoice_id', $b)->value('body');
        $this->assertStringContainsString('INV-B', $body);
        $this->assertStringContainsString('205 000', $body); // balance + fee, thousands separated

        // Sending again does nothing until the next level is reached.
        $again = $this->postJson('/api/v1/accounting3/dunning/send', [])->assertOk()->json();
        $this->assertCount(0, array_filter($again, fn ($s) => ($s['status'] ?? null) === 'sent'));
        $later = now()->addDays(40)->toDateString();
        $next = $this->postJson('/api/v1/accounting3/dunning/send', ['as_of' => $later, 'locale' => 'en'])->assertOk()->json();
        $levels = collect($next)->where('status', 'sent')->pluck('level', 'invoice_id')->all();
        $this->assertSame(3, $levels[$a]); // 50 days overdue: straight to the final notice
        $this->assertSame(3, $levels[$b]);
        $this->getJson("/api/v1/accounting3/dunning/notices?invoice_id=$a")->assertOk();
        $this->assertBooksBalance();
    }

    public function test_stop_dunning_hold_interest_and_no_contact(): void
    {
        $a = $this->invoice('INV-H', 400_000, 30);
        $hold = $this->postJson('/api/v1/accounting3/dunning/holds', ['company_id' => $this->company->id, 'reason' => 'Litige en cours'])->assertCreated()->json();
        $cand = $this->getJson('/api/v1/accounting3/dunning/candidates')->json()[0];
        $this->assertTrue($cand['held']);
        $this->assertFalse($cand['eligible']);
        $r = $this->postJson('/api/v1/accounting3/dunning/send', ['invoice_ids' => [$a]])->json();
        $this->assertSame('held', $r[0]['skipped']);
        $this->assertSame(0, DB::table('dunning_notices')->count());

        $this->postJson("/api/v1/accounting3/dunning/holds/{$hold['id']}/release")->assertOk()->assertJsonPath('active', false);
        // Optional interest: 400,000 x 12% x 30/365 = 3,945.
        $this->putJson('/api/v1/accounting3/dunning/settings', ['interest_enabled' => true, 'interest_rate' => 12])->assertOk();
        $this->assertSame(3_945, $this->getJson('/api/v1/accounting3/dunning/candidates')->json()[0]['interest']);

        // A customer without contact details is logged as no_contact (still counted as sent for escalation).
        $company2 = Company::create(['name' => 'Sans contact', 'city_ledger' => true]);
        $b = $this->invoice('INV-N', 90_000, 12, 0, $company2->id);
        $res = collect($this->postJson('/api/v1/accounting3/dunning/send', ['invoice_ids' => [$b]])->json())->first();
        $this->assertSame('no_contact', $res['status']);

        // Level configuration is validated and permissioned.
        $this->putJson('/api/v1/accounting3/dunning/levels/2', ['days_overdue' => 3])->assertStatus(422); // below level 1
        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $this->actAs($acc, $this->hotel);
        $this->putJson('/api/v1/accounting3/dunning/levels/2', ['fee' => 1])->assertForbidden();
        $this->getJson('/api/v1/accounting3/dunning/candidates')->assertOk();
        $this->assertBooksBalance();
    }
}

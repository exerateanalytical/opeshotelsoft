<?php

namespace Tests\Feature\Payments;

use App\Modules\Accounting\Application\Reconciliation;
use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\BankStatementLine;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\Payments\Application\Drivers\DryRunGateway;
use App\Modules\Payments\Domain\PaymentInbound;
use App\Modules\Payments\Domain\PaymentLink;
use App\Modules\Reservations\Application\ReservationService;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

class InboundAndPayoutsTest extends TestCase
{
    use RepairsRoleSeed;

    private function openLink(int $charge): PaymentLink
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 50_000);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(5)->toDateString(),
        ]);
        $folios = app(FolioService::class);
        $folio = $folios->guestFolioFor($r);
        $folios->charge($folio, 'MISC', $charge);
        $id = $this->postJson('/api/v1/payments/links', ['target_type' => 'folio', 'target_id' => $folio->id, 'payer_phone' => '+237670111222'])->assertCreated()->json('id');

        return PaymentLink::findOrFail($id);
    }

    private function inbound(array $body, ?string $secret = null)
    {
        $raw = json_encode($body);

        return $this->call('POST', "/api/v1/payments/inbound/mtn_momo/{$this->hotel->id}", [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => DryRunGateway::sign($raw, $secret ?? DryRunGateway::secret())], $raw);
    }

    public function test_inbound_callback_with_reference_and_amount_auto_posts_once(): void
    {
        $link = $this->openLink(15_000);
        $body = ['transaction_id' => 'TX-1', 'amount' => $link->amount, 'payer_phone' => '237699999999', 'message' => "Paiement {$link->code}"];
        $this->inbound($body, 'wrong')->assertStatus(401);
        $this->inbound($body)->assertOk()->assertJsonPath('outcome', 'posted');
        $this->inbound($body)->assertOk()->assertJsonPath('outcome', 'posted');
        $this->assertSame(1, PaymentInbound::count());
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertBooksBalance();
    }

    public function test_uncertain_inbound_goes_to_review_and_staff_posts_it(): void
    {
        $link = $this->openLink(15_000);
        // Amount + phone, no reference: 40 points -> review queue.
        $this->inbound(['transaction_id' => 'TX-2', 'amount' => $link->amount, 'payer_phone' => '+237 670111222'])->assertOk()->assertJsonPath('outcome', 'review');
        $queue = $this->getJson('/api/v1/payments/inbound')->assertOk()->json('data');
        $this->assertCount(1, $queue);
        $this->assertSame($link->id, $queue[0]['candidates'][0]['link_id']);
        $this->assertSame(40, $queue[0]['confidence']);

        $this->postJson("/api/v1/payments/inbound/{$queue[0]['id']}/post", ['payment_link_id' => $link->id])->assertOk()->assertJsonPath('status', 'posted');
        $this->postJson("/api/v1/payments/inbound/{$queue[0]['id']}/post", ['payment_link_id' => $link->id])->assertStatus(422);
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertBooksBalance();
    }

    public function test_imported_momo_statement_line_is_matched_posted_and_reconciled(): void
    {
        $link = $this->openLink(12_000);
        $bank = BankAccount::create(['name' => 'MTN marchand', 'kind' => 'mobile_money', 'gl_account' => '521500']);
        $date = now()->toDateString();
        $csv = "date;amount;reference;description\n$date;{$link->amount};{$link->code};Depot 237670111222\n$date;5000;XYZ;Inconnu\n";
        app(Reconciliation::class)->import($bank, $csv, ['date' => 'date', 'amount' => 'amount', 'reference' => 'reference', 'description' => 'description']);

        $this->postJson("/api/v1/payments/bank-accounts/{$bank->id}/scan")->assertOk()
            ->assertJsonPath('scanned', 2)->assertJsonPath('posted', 1)->assertJsonPath('review', 1);
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertNotNull(BankStatementLine::where('reference', $link->code)->value('journal_line_id'), 'statement line reconciled');
        $this->postJson("/api/v1/payments/bank-accounts/{$bank->id}/scan")->assertOk()->assertJsonPath('scanned', 0);
        $this->assertBooksBalance();
    }

    private function hire(string $first, string $method, array $extra): void
    {
        $dept = Department::firstOrCreate(['code' => 'FO'], ['name' => 'Réception', 'cost_center' => 'rooms']);
        $this->postJson('/api/v1/hr/employees', [
            'first_name' => $first, 'last_name' => 'Test', 'hire_date' => now()->startOfMonth()->subYear()->toDateString(),
            'department_id' => $dept->id, 'payment_method' => $method,
            'contract' => ['kind' => 'CDI', 'start_date' => now()->startOfMonth()->subYear()->toDateString(), 'base_salary' => 200_000],
        ] + $extra)->assertCreated();
    }

    public function test_salary_payout_momo_bulk_bank_file_retries_and_balanced_gl(): void
    {
        $this->hire('Mtn', 'mobile_money', ['momo_number' => '677111222']);
        $this->hire('Orange', 'mobile_money', ['momo_number' => '699111000']); // dry run fails on ...000
        $this->hire('Banque', 'bank', ['bank_name' => 'Afriland', 'bank_account' => '10005-00001-123']);
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk();
        $net = PayrollRun::findOrFail($run['id'])->net;

        $payout = $this->postJson('/api/v1/payments/payouts', ['payroll_run_id' => $run['id']])->assertCreated()->json();
        $this->postJson('/api/v1/payments/payouts', ['payroll_run_id' => $run['id']])->assertStatus(422);
        $this->assertSame($net, $payout['total']);
        $channels = collect($payout['items'])->pluck('channel', 'employee_name');
        $this->assertSame('mtn_momo', $channels['Mtn Test'] ?? $channels->first());
        $this->assertContains('orange_money', $channels->all());

        $p = $this->postJson("/api/v1/payments/payouts/{$payout['id']}/process")->assertOk()->json();
        $byStatus = collect($p['items'])->groupBy('status')->map->count();
        $this->assertSame(1, $byStatus['succeeded']);
        $this->assertSame(1, $byStatus['failed']);

        $csv = $this->get("/api/v1/payments/payouts/{$payout['id']}/bank-file")->assertOk()->getContent();
        $this->assertStringContainsString('10005-00001-123', $csv);
        $this->postJson("/api/v1/payments/payouts/{$payout['id']}/confirm")->assertOk()->assertJsonPath('status', 'processing');

        $failed = collect($p['items'])->firstWhere('status', 'failed');
        $this->putJson("/api/v1/payments/payouts/{$payout['id']}/items/{$failed['id']}", ['destination' => '699111222'])->assertOk();
        $this->postJson("/api/v1/payments/payouts/{$payout['id']}/process")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('paid', $net);

        $this->assertSame('paid', PayrollRun::findOrFail($run['id'])->status);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/pay", ['account' => '521500'])->assertStatus(422);
        $this->actAs($this->user, $this->hotel);
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['422100'] ?? 0, 'salaries payable cleared');

        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel);
        $this->getJson('/api/v1/payments/payouts')->assertForbidden();
        $this->get('/payments/payouts')->assertForbidden();
    }
}

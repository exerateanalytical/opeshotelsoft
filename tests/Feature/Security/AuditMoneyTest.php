<?php

namespace Tests\Feature\Security;

use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\HR\Domain\Department;
use App\Modules\Payments\Application\Drivers\DryRunGateway;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Reservations\Application\ReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

/**
 * SEC1 audit regressions: money:payments:refund-toctou-double-refund,
 * money:payouts:process-race-double-disbursement, money:payouts:single-role-destination-diversion,
 * authz:pos:view-permission-can-settle-and-charge-rooms.
 */
class AuditMoneyTest extends TestCase
{
    use RepairsRoleSeed;

    private function paidIntent(): PaymentIntent
    {
        $this->putJson('/api/v1/payments/gateways/mtn_momo', ['credentials' => [
            'base_url' => 'https://momo.test', 'subscription_key' => 'sub-key-1234', 'api_user' => 'user', 'api_key' => 'apikey-9876',
            'target_environment' => 'sandbox', 'callback_secret' => 'cb-secret-5555',
        ]])->assertOk();
        Http::fake([
            'momo.test/collection/token/' => Http::response(['access_token' => 'tok']),
            'momo.test/disbursement/token/' => Http::response(['access_token' => 'tok2']),
            'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202),
            'momo.test/disbursement/v1_0/refund' => Http::sequence()->push(null, 500)->push(null, 202)->push(null, 202),
            'momo.test/collection/v1_0/requesttopay/*' => Http::response(['status' => 'PENDING']),
        ]);
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 50_000);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(5)->toDateString(),
        ]);
        $intent = PaymentIntent::findOrFail($this->postJson('/api/v1/payments/intents', [
            'provider' => 'mtn_momo', 'amount' => 30_000, 'reservation_id' => $r->id, 'payer_phone' => '237670000001',
        ])->json('id'));
        $raw = json_encode(['status' => 'SUCCESSFUL', 'externalId' => $intent->reference]);
        $this->call('PUT', "/api/v1/payments/callbacks/mtn_momo/{$intent->reference}", [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => DryRunGateway::sign($raw, 'cb-secret-5555')], $raw)->assertOk();

        return $intent->fresh();
    }

    public function test_refund_is_atomic_and_rechecked_under_lock(): void
    {
        $intent = $this->paidIntent();
        // Provider failure: nothing is posted and nothing is counted as refunded.
        $this->postJson("/api/v1/payments/intents/{$intent->id}/refunds", ['amount' => 10_000, 'reason' => 'x'])->assertStatus(502);
        $this->assertSame(0, (int) $intent->fresh()->refunded_amount);
        $this->assertSame(0, FolioLine::where('folio_id', $intent->fresh()->folio_id)->where('type', 'refund')->count());
        // Then two refunds: the second one is measured against the locked, updated row and refused.
        $this->postJson("/api/v1/payments/intents/{$intent->id}/refunds", ['amount' => 20_000, 'reason' => 'x'])->assertCreated();
        $this->postJson("/api/v1/payments/intents/{$intent->id}/refunds", ['amount' => 20_000, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame(20_000, (int) $intent->fresh()->refunded_amount);
        $this->assertSame(1, FolioLine::where('folio_id', $intent->fresh()->folio_id)->where('type', 'refund')->count());
        $refundCalls = collect(Http::recorded(fn ($req) => str_ends_with($req->url(), '/disbursement/v1_0/refund')))->count();
        $this->assertSame(2, $refundCalls, 'the refused refund never reaches the provider');
    }

    private function payoutRun(): array
    {
        $dept = Department::firstOrCreate(['code' => 'FO'], ['name' => 'Réception', 'cost_center' => 'rooms']);
        foreach ([['Mtn', 'mobile_money', ['momo_number' => '677111222']], ['Orange', 'mobile_money', ['momo_number' => '699111000']], ['Banque', 'bank', ['bank_name' => 'Afriland', 'bank_account' => '10005-00001-123']]] as [$first, $method, $extra]) {
            $this->postJson('/api/v1/hr/employees', [
                'first_name' => $first, 'last_name' => 'Test', 'hire_date' => now()->startOfMonth()->subYear()->toDateString(),
                'department_id' => $dept->id, 'payment_method' => $method,
                'contract' => ['kind' => 'CDI', 'start_date' => now()->startOfMonth()->subYear()->toDateString(), 'base_salary' => 200_000],
            ] + $extra)->assertCreated();
        }
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk();

        return $this->postJson('/api/v1/payments/payouts', ['payroll_run_id' => $run['id']])->assertCreated()->json();
    }

    public function test_payout_processing_is_serialised_and_destination_changes_are_controlled(): void
    {
        $payout = $this->payoutRun();
        $p = $this->postJson("/api/v1/payments/payouts/{$payout['id']}/process")->assertOk()->json();
        $succeeded = collect($p['items'])->firstWhere('status', 'succeeded');
        $failed = collect($p['items'])->firstWhere('status', 'failed');
        $this->assertNotNull($succeeded);
        // Re-processing never disburses a paid item again (attempts frozen at 1).
        $this->postJson("/api/v1/payments/payouts/{$payout['id']}/process")->assertOk();
        $this->assertSame(1, (int) DB::table('payout_items')->where('id', $succeeded['id'])->value('attempts'));
        // A payouts operator without hr.manage rejects the bank item and tries to re-point the salary to another account: refused.
        $op = $this->makeUser($this->group, 'payroll-officer', allHotels: true);
        $op->givePermissionTo('payments.payouts');
        $this->actAs($op, $this->hotel);
        $this->assertFalse($op->can('hr.manage'));
        $bank = collect($p['items'])->firstWhere('channel', 'bank');
        $this->postJson("/api/v1/payments/payouts/{$payout['id']}/reject", ['item_ids' => [$bank['id']], 'reason' => 'bank rejected'])->assertOk();
        $this->putJson("/api/v1/payments/payouts/{$payout['id']}/items/{$bank['id']}", ['destination' => '99999-ATTACKER-1'])->assertStatus(403);
        $this->assertSame('10005-00001-123', DB::table('payout_items')->where('id', $bank['id'])->value('destination'));
        // The account on the employee record is accepted; a corrected Mobile Money number is accepted; both are audited.
        $this->putJson("/api/v1/payments/payouts/{$payout['id']}/items/{$bank['id']}", ['destination' => '10005-00001-123'])->assertOk();
        $this->putJson("/api/v1/payments/payouts/{$payout['id']}/items/{$failed['id']}", ['destination' => '699111222'])->assertOk();
        $this->assertSame(2, DB::table('domain_events')->where('type', 'payout.item_destination_changed')->count());
    }

    public function test_pos_viewer_can_read_but_not_write(): void
    {
        $resto = Outlet::create(['code' => 'RES', 'name' => 'Restaurant', 'kind' => 'restaurant']);
        $beer = MenuItem::create(['code' => 'B33', 'name' => '33 Export', 'price' => 2_000, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $resto->id, 'covers' => 1])->assertCreated()->json();
        $viewer = $this->makeUser($this->group, 'auditor', allHotels: true);
        $this->actAs($viewer, $this->hotel);
        $this->assertTrue($viewer->can('pos.view'));
        $this->getJson('/api/v1/pos/checks')->assertOk();
        $this->getJson("/api/v1/pos/checks/{$check['id']}")->assertOk();
        $this->postJson('/api/v1/pos/checks', ['outlet_id' => $resto->id, 'covers' => 1])->assertForbidden();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $beer->id, 'quantity' => 1])->assertForbidden();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 2_000]]])->assertForbidden();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/charge-to-room", ['reservation_id' => 1])->assertForbidden();
        $this->postJson('/api/v1/pos/sync', ['checks' => []])->assertForbidden();
    }
}

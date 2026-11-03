<?php

namespace Tests\Feature\Payments;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Guests\Domain\Company;
use App\Modules\Payments\Application\PayLinkService;
use App\Modules\Payments\Domain\PaymentLink;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

class PayLinksTest extends TestCase
{
    use RepairsRoleSeed;

    private function reservation(): Reservation
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 50_000);

        return app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(5)->toDateString(),
        ]);
    }

    private function folioWithCharge(int $amount): Folio
    {
        $folios = app(FolioService::class);
        $folio = $folios->guestFolioFor($this->reservation());
        $folios->charge($folio, 'MISC', $amount);

        return $folio->fresh();
    }

    private function link(string $type, int $id, array $extra = []): array
    {
        return $this->postJson('/api/v1/payments/links', ['target_type' => $type, 'target_id' => $id] + $extra)->assertCreated()->json();
    }

    /** Path of the public API for a link URL (…/pay/CODE/SIG). */
    private function api(array $link): string
    {
        return '/api/v1/pay/'.$link['code'].'/'.PayLinkService::signature($link['code']);
    }

    private function payAndPoll(array $link, array $body): array
    {
        $intent = $this->postJson($this->api($link).'/intents', $body)->assertCreated()->json();

        return $this->getJson($this->api($link)."/intents/{$intent['reference']}")->assertOk()->json();
    }

    public function test_folio_link_has_qr_and_takes_partial_payments_to_full(): void
    {
        $folio = $this->folioWithCharge(30_000);
        $link = $this->link('folio', $folio->id);
        $this->assertSame($folio->balance, $link['amount']);
        $this->assertStringContainsString('<svg', $link['qr_svg']);
        $this->assertStringContainsString("/pay/{$link['code']}/", $link['url']);

        $this->app['auth']->forgetGuards();
        $this->getJson($this->api($link))->assertOk()->assertJsonPath('balance', $link['amount'])->assertJsonPath('payable', true);
        $this->getJson('/api/v1/pay/'.$link['code'].'/0000000000')->assertNotFound();
        $this->get('/pay/'.$link['code'].'/'.PayLinkService::signature($link['code']))->assertOk();

        $first = $this->payAndPoll($link, ['provider' => 'mtn_momo', 'amount' => 10_000, 'payer_phone' => '+237670000001']);
        $this->assertSame('succeeded', $first['status']);
        $this->assertSame('partially_paid', $first['link']['status']);
        $this->postJson($this->api($link).'/intents', ['provider' => 'mtn_momo', 'amount' => $link['amount'], 'payer_phone' => '+237670000001'])->assertStatus(422);

        $rest = $this->payAndPoll($link, ['provider' => 'orange_money', 'payer_phone' => '+237690000001']);
        $this->assertSame($link['amount'] - 10_000, $rest['amount']);
        $this->assertSame('paid', $rest['link']['status']);
        $this->postJson($this->api($link).'/intents', ['provider' => 'mtn_momo', 'amount' => 100, 'payer_phone' => '+237670000001'])->assertStatus(422);

        $this->actAs($this->user, $this->hotel);
        $this->assertSame(0, $folio->fresh()->balance);
        $b = $this->assertBooksBalance();
        $this->assertSame($link['amount'], $b['521500']);
    }

    public function test_failed_payment_posts_nothing_and_full_only_link_rejects_partial(): void
    {
        $folio = $this->folioWithCharge(20_000);
        $link = $this->link('folio', $folio->id, ['allow_partial' => false]);
        $this->postJson($this->api($link).'/intents', ['provider' => 'mtn_momo', 'amount' => 5_000, 'payer_phone' => '+237670000001'])->assertStatus(422);
        $failed = $this->payAndPoll($link, ['provider' => 'mtn_momo', 'payer_phone' => '+237670000000']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('open', $failed['link']['status']);
        $this->assertSame($link['amount'], $folio->fresh()->balance);
    }

    public function test_booking_deposit_link_raises_deposit_paid(): void
    {
        $r = $this->reservation();
        $link = $this->link('reservation', $r->id, ['amount' => 25_000]);
        $res = $this->payAndPoll($link, ['provider' => 'card', 'payer_email' => 'guest@test.cm']);
        $this->assertSame('succeeded', $res['status']);
        $this->assertSame(25_000, $r->fresh()->deposit_paid);
        $this->assertSame(-25_000, Folio::where('reservation_id', $r->id)->where('kind', 'guest')->firstOrFail()->balance);
        $this->assertBooksBalance();
    }

    public function test_pos_check_paid_by_qr_in_two_parts_settles_check_and_clears_advances(): void
    {
        $outlet = Outlet::create(['code' => 'RES', 'name' => 'Restaurant', 'kind' => 'restaurant']);
        $item = MenuItem::create(['code' => 'STK', 'name' => 'Steak frites', 'price' => 8_000, 'charge_code' => 'FNB', 'kitchen_station' => 'grill']);
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $outlet->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $item->id, 'quantity' => 2])->assertCreated();
        $total = Check::findOrFail($check['id'])->total;

        $link = $this->link('pos_check', $check['id']);
        $this->assertSame($total, $link['amount']);
        $this->payAndPoll($link, ['provider' => 'mtn_momo', 'amount' => 6_000, 'payer_phone' => '+237670000001']);
        $this->actAs($this->user, $this->hotel);
        $this->assertSame('open', Check::findOrFail($check['id'])->status);
        $this->assertSame(-6_000, $this->assertBooksBalance()['419100']);

        $this->payAndPoll($link, ['provider' => 'orange_money', 'payer_phone' => '+237690000001']);
        $this->actAs($this->user, $this->hotel);
        $settled = Check::findOrFail($check['id']);
        $this->assertSame('settled', $settled->status);
        $this->assertSame('PAYLINK', $settled->payments()->value('method_code'));
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['419100'] ?? 0, 'customer advances cleared to revenue');
        $this->assertSame($total, $b['521500']);
    }

    public function test_city_ledger_invoice_link_marks_invoice_paid(): void
    {
        $company = Company::create(['name' => 'SONARA', 'city_ledger' => true, 'credit_limit' => 1_000_000]);
        $folios = app(FolioService::class);
        $ledger = $folios->open('city_ledger', 'SONARA', null, null, $company->id);
        $folios->charge($ledger, 'MISC', 50_000);
        $invoice = Invoice::create(['number' => 'INV-77', 'bill_to_name' => 'SONARA', 'company_id' => $company->id, 'issue_date' => now()->toDateString(),
            'currency' => 'XAF', 'total' => $ledger->fresh()->balance, 'paid' => 0, 'tax_total' => 0, 'lines' => [], 'taxes' => [], 'status' => 'issued']);

        $link = $this->link('invoice', $invoice->id);
        $this->payAndPoll($link, ['provider' => 'mtn_momo', 'amount' => 20_000, 'payer_phone' => '+237670000001']);
        $this->actAs($this->user, $this->hotel);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->payAndPoll($link, ['provider' => 'mtn_momo', 'payer_phone' => '+237670000001']);
        $this->actAs($this->user, $this->hotel);
        $this->assertSame('paid', $invoice->fresh()->status);
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['411200'] ?? 0);
    }

    public function test_links_need_permission_and_cancel_closes_the_link(): void
    {
        $folio = $this->folioWithCharge(10_000);
        $link = $this->link('folio', $folio->id);
        $this->postJson("/api/v1/payments/links/{$link['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($this->api($link).'/intents', ['provider' => 'mtn_momo', 'payer_phone' => '+237670000001'])->assertStatus(422);
        $this->assertSame('cancelled', PaymentLink::findOrFail($link['id'])->status);

        $housekeeper = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($housekeeper, $this->hotel);
        $this->postJson('/api/v1/payments/links', ['target_type' => 'folio', 'target_id' => $folio->id])->assertForbidden();
        $this->get('/payments/links')->assertForbidden();
    }
}

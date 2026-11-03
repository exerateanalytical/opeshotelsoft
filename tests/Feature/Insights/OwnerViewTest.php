<?php

namespace Tests\Feature\Insights;

use App\Modules\Insights\Application\AlertService;
use App\Modules\Insights\Domain\Alert;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Platform\Tenancy\TenantContext;
use Tests\TestCase;

class OwnerViewTest extends TestCase
{
    private function sellSomething(): void
    {
        $bar = Outlet::create(['code' => 'BAR', 'name' => 'Bar', 'kind' => 'bar']);
        $beer = MenuItem::create(['code' => 'BEER', 'name' => 'Beer', 'price' => 10_000, 'charge_code' => 'BAR']);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $c = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $bar->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/items", ['menu_item_id' => $beer->id, 'quantity' => 2])->assertCreated();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 20_000]]])->assertSuccessful();
    }

    public function test_owner_kpis_revenue_occupancy_adr_and_cash(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 4, rate: 50_000);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM1', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => Room::orderBy('number')->first()->id])->assertOk();
        $this->sellSomething();
        $date = $this->hotel->fresh()->business_date->toDateString();
        $this->postJson('/api/v1/night-audit')->assertOk();

        $k = $this->getJson("/api/v1/insights/owner?date=$date")->assertOk()->json('today');
        $this->assertSame(1, $k['occupied']);
        $this->assertSame(25.0, (float) $k['occupancy']);
        $this->assertGreaterThan(0, $k['room_revenue']);
        $this->assertSame($k['room_revenue'], $k['adr']);
        $this->assertSame($k['room_revenue'] + 20_000, $k['revenue']);
        $this->assertSame(20_000, collect($k['cash_by_method'])->firstWhere('method', 'CASH')['amount']);
    }

    public function test_daily_digest_by_email_and_whatsapp_in_french_and_english(): void
    {
        $this->sellSomething();
        app(AlertService::class)->raise('cashier_variance', 'x', 'Cash short on shift #1', ['amount' => 7_000]);
        $date = now()->toDateString();

        $this->postJson('/api/v1/insights/digest/send', ['date' => $date])->assertStatus(422); // off by default
        $this->putJson('/api/v1/insights/settings', [
            'digest_enabled' => true, 'digest_emails' => ['owner@test.cm'], 'digest_whatsapp' => ['+237690000009'], 'digest_locale' => 'en',
        ])->assertOk();

        $this->artisan('insights:scan', ['--date' => $date, '--hotel' => $this->hotel->id])->assertSuccessful();
        $this->artisan('insights:scan', ['--date' => $date, '--hotel' => $this->hotel->id])->assertSuccessful(); // sent once per date

        $email = OutboxMessage::where(['event' => 'insights.owner_digest', 'channel' => 'email'])->sole();
        $wa = OutboxMessage::where(['event' => 'insights.owner_digest', 'channel' => 'whatsapp'])->sole();
        $this->assertSame('owner@test.cm', $email->recipient);
        $this->assertSame('+237690000009', $wa->recipient);
        $this->assertStringContainsString('Owner report', $email->subject);
        $this->assertStringContainsString('Revenue: 20 000 FCFA', $email->body);
        $this->assertStringContainsString('Cash short on shift #1', $wa->body);
        $this->assertSame(0, OutboxMessage::where(['event' => 'insights.owner_digest', 'channel' => 'sms'])->count());

        $this->putJson('/api/v1/insights/settings', ['digest_locale' => 'fr'])->assertOk();
        $this->postJson('/api/v1/insights/digest/send', ['date' => $date])->assertOk()->assertJsonPath('messages', 2);
        $this->assertStringContainsString("Chiffre d'affaires : 20 000 FCFA", OutboxMessage::where('event', 'insights.owner_digest')->where('locale', 'fr')->where('channel', 'email')->sole()->body);
        $this->assertCount(1, $this->getJson('/api/v1/insights/digests')->json());
    }

    public function test_group_view_and_tenant_isolation(): void
    {
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $ctx = app(TenantContext::class);
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        $ctx->set($other->group_id, $other->id, [$other->id], $this->user);
        app(AlertService::class)->raise('cashier_variance', 'b', 'Bonaberi short', ['amount' => 9_000, 'severity' => 'high']);
        $ctx->set(...$saved);
        app(AlertService::class)->raise('discount_above', 'a', 'Akwa discount', ['amount' => 3_000]);

        $this->getJson('/api/v1/insights/alerts')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.title', 'Akwa discount');
        $foreign = Alert::withoutGlobalScopes()->where('hotel_id', $other->id)->sole();
        $this->getJson("/api/v1/insights/alerts/{$foreign->id}")->assertNotFound();

        $g = $this->withHeaders(['X-Hotel-Id' => null])->getJson('/api/v1/insights/group')->assertOk()->json();
        $this->assertCount(2, $g['hotels']);
        $this->assertSame(2, $g['totals']['open_alerts']);
        $this->assertSame('Bonaberi short', $g['top_alerts'][0]['title']);

        // A hotel owner limited to one hotel sees only that hotel in the group view.
        $owner = $this->makeUser($this->group, 'hotel-owner', hotels: [$this->hotel]);
        $g = $this->actAs($owner, null)->getJson('/api/v1/insights/group')->assertOk()->json();
        $this->assertSame(['AKW'], array_column($g['hotels'], 'code'));

        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel)->getJson('/api/v1/insights/group')->assertForbidden();
    }

    public function test_owner_web_pages_need_insights_permissions(): void
    {
        $this->withoutVite();
        $this->actingAs($this->makeUser($this->group, 'hotel-owner', hotels: [$this->hotel]))->get('/owner')->assertOk();
        $this->actingAs($this->makeUser($this->group, 'hotel-owner', hotels: [$this->hotel]))->get('/insights/settings')->assertOk();
        $this->actingAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]))->get('/owner')->assertForbidden();
        $this->actingAs($this->makeUser($this->group, 'auditor', hotels: [$this->hotel]))->get('/insights/settings')->assertForbidden();
    }

    public function test_insights_lang_files_have_identical_keys(): void
    {
        $fr = json_decode(file_get_contents(lang_path('fr/insights.json')), true);
        $en = json_decode(file_get_contents(lang_path('en/insights.json')), true);
        $this->assertNotEmpty($fr);
        $this->assertSame(array_keys($fr), array_keys($en));
    }
}

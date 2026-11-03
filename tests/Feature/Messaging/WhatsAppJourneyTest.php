<?php

namespace Tests\Feature\Messaging;

use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\GuestPortal\Domain\GuestOrder;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Application\WhatsApp\GuestJourney;
use App\Modules\Notifications\Application\WhatsApp\WhatsAppMessenger;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppAccount;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppConversation;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppMessage;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppOptOut;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppTemplate;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use Tests\TestCase;

class WhatsAppJourneyTest extends TestCase
{
    private const SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.whatsapp.app_secret' => self::SECRET, 'notifications.whatsapp.journey' => true]);
        WhatsAppAccount::create(['phone_number_id' => 'PNID1', 'display_phone' => '+237600000000']);
    }

    private function guest(bool $optIn = true, string $lang = 'fr'): Guest
    {
        $g = Guest::create(['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '+237 690 11 22 33', 'language' => $lang, 'id_number' => 'X1']);
        NotificationPreference::create(['guest_id' => $g->id, 'whatsapp' => $optIn]);

        return $g;
    }

    private function stay(Guest $g, bool $checkIn = true): Reservation
    {
        [$type, $plan] = $this->seedInventory();
        $r = app(ReservationService::class)->create([
            'guest_id' => $g->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(), 'room_id' => Room::first()->id,
        ]);
        if ($checkIn) {
            app(FrontDeskService::class)->checkIn($r);
        }

        return $r->fresh();
    }

    private function webhook(array $value, ?string $sig = null)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages',
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => 'PNID1']] + $value]]]]]);
        $sig ??= 'sha256='.hash_hmac('sha256', $body, self::SECRET);

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $body);
    }

    private function inbound(string $text, string $from = '237690112233', string $id = 'wamid.IN1')
    {
        return $this->webhook([
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Awa']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]);
    }

    public function test_webhook_rejects_bad_signature_and_verifies_handshake(): void
    {
        $this->webhook(['messages' => []], 'sha256=deadbeef')->assertStatus(401);
        $this->webhook(['messages' => []], '')->assertStatus(401);
        $this->webhook(['messages' => []])->assertOk();

        config(['notifications.whatsapp.verify_token' => 'vt']);
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=vt&hub_challenge=42')->assertOk()->assertSee('42');
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=no&hub_challenge=42')->assertForbidden();
    }

    public function test_inbound_creates_conversation_linked_to_stay_and_is_idempotent(): void
    {
        $g = $this->guest();
        $r = $this->stay($g);
        $this->inbound('Bonjour, une serviette svp')->assertOk()->assertJson(['messages' => 1]);
        $this->inbound('Bonjour, une serviette svp')->assertOk(); // Meta retry

        $c = WhatsAppConversation::where('wa_id', '237690112233')->sole();
        $this->assertSame($g->id, $c->guest_id);
        $this->assertSame($r->id, $c->reservation_id);
        $this->assertTrue($c->windowOpen());
        $this->assertSame(1, WhatsAppMessage::where('direction', 'in')->count());

        $show = $this->getJson("/api/v1/messaging/conversations/{$c->id}")->assertOk()->json();
        $this->assertSame('Bonjour, une serviette svp', collect($show['messages'])->firstWhere('direction', 'in')['body']);
        $this->getJson('/api/v1/messaging/conversations')->assertOk()->assertJsonPath('data.0.id', $c->id);

        // Staff reply inside the window, then delivery and read statuses.
        $out = $this->postJson("/api/v1/messaging/conversations/{$c->id}/reply", ['text' => 'Tout de suite !'])->assertCreated()->json();
        $this->assertSame('sent', $out['status']);
        $this->assertTrue($out['dry_run']);
        foreach (['read', 'delivered'] as $s) { // out of order: read wins
            $this->webhook(['statuses' => [['id' => $out['wamid'], 'status' => $s, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '237690112233']]])->assertOk();
        }
        $this->assertSame('read', WhatsAppMessage::find($out['id'])->status);
    }

    public function test_stop_opts_out_and_blocks_further_messages(): void
    {
        $g = $this->guest();
        $r = $this->stay($g, checkIn: false);
        $this->inbound('stop')->assertOk();

        $this->assertTrue(WhatsAppOptOut::where('wa_id', '237690112233')->exists());
        $this->assertFalse(NotificationPreference::where('guest_id', $g->id)->value('whatsapp'));
        $c = WhatsAppConversation::sole();
        $this->assertSame(1, WhatsAppMessage::where('journey', 'opt_out')->count(), 'opt-out confirmation sent');

        $this->postJson("/api/v1/messaging/conversations/{$c->id}/reply", ['text' => 'hello'])->assertStatus(422);
        $this->assertNull(app(WhatsAppMessenger::class)->journey('pre_arrival', $r));

        $this->inbound('START', id: 'wamid.IN2')->assertOk();
        $this->assertFalse(WhatsAppOptOut::where('wa_id', '237690112233')->exists());
        $this->assertTrue(NotificationPreference::where('guest_id', $g->id)->value('whatsapp'));
    }

    public function test_24h_window_rule(): void
    {
        $g = $this->guest(lang: 'en');
        $r = $this->stay($g, checkIn: false);
        $c = app(WhatsAppMessenger::class)->conversationFor('237690112233', $g, $r);
        $c->forceFill(['last_inbound_at' => now()->subHours(25)])->save();

        $this->postJson("/api/v1/messaging/conversations/{$c->id}/reply", ['text' => 'hi'])->assertStatus(422)->assertJsonPath('title', 'Outside window');
        $m = $this->postJson("/api/v1/messaging/conversations/{$c->id}/template", ['journey' => 'pre_arrival'])->assertCreated()->json();
        $this->assertSame('template', $m['type']);
        $this->assertSame('pre_arrival_checkin', $m['template_name']);
        $this->assertStringContainsString('check in online', $m['body']);

        // Live mode: templates that Meta has not approved are not sent.
        config(['notifications.whatsapp.token' => 't', 'notifications.whatsapp.phone_number_id' => 'PNID1']);
        $m = $this->postJson("/api/v1/messaging/conversations/{$c->id}/template", ['journey' => 'review'])->assertCreated()->json();
        $this->assertSame('blocked', $m['status']);

        // Inside the window a free-form session message is used even for journey steps.
        config(['notifications.whatsapp.token' => null]);
        $c->forceFill(['last_inbound_at' => now()->subHour()])->save();
        $this->postJson("/api/v1/messaging/conversations/{$c->id}/reply", ['text' => 'hi'])->assertCreated();
        $m = $this->postJson("/api/v1/messaging/conversations/{$c->id}/template", ['journey' => 'review'])->assertCreated()->json();
        $this->assertSame('text', $m['type']);
    }

    public function test_journey_triggers_on_events(): void
    {
        $g = $this->guest();
        $r = $this->stay($g, checkIn: false);
        $this->assertSame(1, WhatsAppMessage::where(['journey' => 'booking_confirmation', 'reservation_id' => $r->id])->count());
        $this->assertSame('template', WhatsAppMessage::where('journey', 'booking_confirmation')->value('type'));

        // Pre-arrival via the daily command for tomorrow's arrival.
        $r->update(['arrival' => now()->addDay()->toDateString(), 'departure' => now()->addDays(2)->toDateString()]);
        $this->artisan('whatsapp:journey')->assertSuccessful();
        $this->artisan('whatsapp:journey')->assertSuccessful();
        $pre = WhatsAppMessage::where(['journey' => 'pre_arrival', 'reservation_id' => $r->id])->sole();
        $this->assertStringContainsString('#checkin', $pre->body);

        $r->update(['arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString()]);
        app(FrontDeskService::class)->checkIn($r->fresh());
        $welcome = WhatsAppMessage::where(['journey' => 'welcome', 'reservation_id' => $r->id])->sole();
        $this->assertStringContainsString('/g/order/'.$r->id, $welcome->body);

        // Departure day: bill with a signed pay link.
        $this->hotel->forceFill(['business_date' => $r->departure->toDateString()])->save();
        $fs = app(\App\Modules\FrontOffice\Application\FolioService::class);
        $fs->charge($fs->guestFolioFor($r->fresh()), 'FNB', 15_000);
        app(GuestJourney::class)->daily();
        $bill = WhatsAppMessage::where(['journey' => 'bill', 'reservation_id' => $r->id])->sole();
        $this->assertStringContainsString('/pay/', $bill->body);

        // No WhatsApp without opt-in.
        $other = Guest::create(['first_name' => 'No', 'last_name' => 'Optin', 'phone' => '+237699999999']);
        [$type, $plan] = [Reservation::first()->room_type_id, Reservation::first()->rate_plan_id];
        $r2 = app(ReservationService::class)->create(['guest_id' => $other->id, 'room_type_id' => $type, 'rate_plan_id' => $plan,
            'arrival' => now()->addDays(5)->toDateString(), 'departure' => now()->addDays(6)->toDateString()]);
        $this->assertSame(0, WhatsAppMessage::where('reservation_id', $r2->id)->count());
    }

    public function test_guest_order_page_creates_room_charge_check_with_balanced_books(): void
    {
        $g = $this->guest();
        $r = $this->stay($g);
        $outlet = Outlet::create(['code' => 'RS', 'name' => 'Room service', 'kind' => 'room_service']);
        $club = MenuItem::create(['code' => 'CLUB', 'name' => 'Club sandwich', 'price' => 6_000, 'charge_code' => 'FNB', 'kitchen_station' => 'kitchen', 'category' => 'Food']);

        $url = app(GuestLinks::class)->order($r);
        $page = $this->get($url)->assertOk();
        $submit = $page->viewData('page')['props']['submit_url'];
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->postJson('/api/v1/guest/order/'.$r->id, ['items' => [['menu_item_id' => $club->id, 'quantity' => 1]]])->assertForbidden();

        $res = $this->postJson($submit, ['items' => [['menu_item_id' => $club->id, 'quantity' => 2]], 'notes' => 'Sans mayo', 'client_uuid' => 'u-1'])
            ->assertCreated()->json();
        $this->postJson($submit, ['items' => [['menu_item_id' => $club->id, 'quantity' => 2]], 'client_uuid' => 'u-1'])->assertCreated();
        $this->assertSame(1, GuestOrder::count());

        $check = Check::findOrFail($res['check_id']);
        $this->assertSame($outlet->id, $check->outlet_id);
        $this->assertSame($r->id, $check->reservation_id);
        $this->assertSame(12_000, (int) $check->total);
        $this->assertNotNull($check->lines()->first()->fired_at, 'kitchen ticket fired');
        $this->assertDatabaseHas('domain_events', ['type' => 'pos.order_fired']);

        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $this->assertGreaterThanOrEqual(12_000, $folio['balance']);
        $this->assertBooksBalance();
        $this->assertSame(1, WhatsAppMessage::where('journey', 'order_confirmation')->count());
    }

    public function test_template_registry_and_permissions(): void
    {
        $list = $this->getJson('/api/v1/messaging/templates')->assertOk()->json();
        $this->assertTrue($list['dry_run']);
        $this->assertEqualsCanonicalizing(['fr', 'en'], collect($list['templates'])->where('journey', 'welcome')->pluck('locale')->all());
        $t = WhatsAppTemplate::where('journey', 'welcome')->where('locale', 'en')->first();
        $this->putJson("/api/v1/messaging/templates/{$t->id}", ['meta_status' => 'approved', 'meta_template_id' => '123'])->assertOk()->assertJsonPath('meta_status', 'approved');
        $this->putJson("/api/v1/messaging/templates/{$t->id}", ['body' => 'Changed {{1}}'])->assertOk()->assertJsonPath('meta_status', 'draft');

        $hk = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($hk, $this->hotel)->getJson('/api/v1/messaging/conversations')->assertForbidden();
        $rec = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($rec, $this->hotel)->getJson('/api/v1/messaging/conversations')->assertOk();
        $this->putJson("/api/v1/messaging/templates/{$t->id}", ['meta_status' => 'approved'])->assertForbidden();
    }
}

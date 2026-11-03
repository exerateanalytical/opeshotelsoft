<?php

namespace Tests\Feature\Member;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Domain\Card;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppConversation;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Group;
use App\Platform\Events\StoredEvent;
use App\Platform\Tenancy\TenantContext;

class MemberPortalTest extends MemberTestCase
{
    private function page(string $url)
    {
        return $this->inertia($url);
    }

    public function test_card_and_points_match_the_loyalty_module(): void
    {
        [$a, $guest, $m] = $this->member('card@test.cm');
        app(LoyaltyService::class)->adjust($m, 750, 'Welcome gift');
        $card = Card::where('loyalty_membership_id', $m->id)->first();
        $this->loginAs($a);

        $p = $this->page('/account/card')->assertOk()->json('props.data');
        $this->assertSame($card->card_number, $p['card']['card_number']);
        $this->assertSame(750, $p['member']['points']);
        $this->assertSame($m->number, $p['member']['number']);
        $this->assertSame('SILVER', $p['member']['tier']['code']);
        $this->assertStringContainsString('<svg', $p['card']['qr_svg']);
        $this->assertSame($card->token, $card->fresh()->token);

        $pts = $this->page('/account/points')->assertOk()->json('props.data');
        $this->assertSame(750, $pts['closing']);
        $this->assertSame('Welcome gift', $pts['lines'][0]['description']);
        $this->assertSame(7500, $p['member']['points_value'], 'point value of the programme (10 XAF per point)');
        $this->get('/account/points/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/account/card/{$card->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_member_can_block_and_replace_only_their_own_card(): void
    {
        [$a, , $m] = $this->member('own@test.cm');
        [, , $other] = $this->member('other@test.cm', 'Bob', 'Other');
        $mine = Card::where('loyalty_membership_id', $m->id)->first();
        $theirs = Card::where('loyalty_membership_id', $other->id)->first();
        $this->loginAs($a);

        $this->postJson("/account/card/{$theirs->id}/block")->assertNotFound();
        $this->get("/account/card/{$theirs->id}/pdf")->assertNotFound();
        $this->postJson("/account/card/{$mine->id}/replace")->assertOk()->assertJsonPath('card.status', 'active');
        $this->assertSame('replaced', $mine->fresh()->status);
        $new = Card::where('loyalty_membership_id', $m->id)->where('status', 'active')->first();
        $this->postJson("/account/card/{$new->id}/block")->assertOk()->assertJsonPath('card.status', 'blocked');
        $this->assertSame('active', $theirs->fresh()->status);
    }

    public function test_member_without_membership_can_join_the_club(): void
    {
        [$a] = $this->member('join@test.cm', enrol: false);
        $this->loginAs($a);
        $this->page('/account/card')->assertOk()->assertJsonPath('props.data.can_join', true)->assertJsonPath('props.data.card', null);
        $this->postJson('/account/card/join')->assertOk()->assertJsonPath('member.tier.code', 'SILVER');
    }

    public function test_search_book_modify_and_cancel_through_the_booking_engine(): void
    {
        $this->seedInventory(rooms: 2, rate: 40_000);
        [$a, $guest] = $this->member('book@test.cm');
        $this->loginAs($a);
        $arrival = now()->addDays(10)->toDateString();
        $departure = now()->addDays(12)->toDateString();

        $search = $this->postJson('/account/book/search', ['arrival' => $arrival, 'departure' => $departure, 'adults' => 2])->assertOk()->json();
        $offer = $search['results'][0]['offers'][0];
        $this->assertSame(80_000, $offer['total']);
        $this->assertTrue($search['member']);

        $b = $this->postJson('/account/book', [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $offer['room_type']['id'], 'rate_plan_id' => $offer['rate_plan']['id'],
            'arrival' => $arrival, 'departure' => $departure, 'adults' => 2,
        ])->assertCreated()->json();
        $r = Reservation::withoutGlobalScopes()->where('number', $b['confirmation'])->first();
        $this->assertSame($guest->id, $r->guest_id, 'booked on the member\'s own profile');
        $this->assertSame('confirmed', $r->status);

        $list = $this->page('/account/bookings')->assertOk()->json('props.data');
        $this->assertSame($b['confirmation'], $list['upcoming'][0]['number']);
        $detail = $this->getJson("/account/bookings/{$b['confirmation']}")->assertOk()->json();
        $this->assertTrue($detail['cancel']['allowed']);
        $this->assertSame($this->hotel->name, $detail['hotel']['name']);

        $quote = $this->postJson("/account/bookings/{$b['confirmation']}/modify", ['arrival' => $arrival, 'departure' => now()->addDays(13)->toDateString(), 'apply' => false]);
        if ($quote->status() === 200) {
            $this->assertSame(40_000, $quote->json('difference'));
            $this->assertSame($departure, $r->fresh()->departure->toDateString(), 'a quote changes nothing');
        } else {
            $quote->assertStatus(422); // hotel rules close online changes
        }

        $this->postJson("/account/bookings/{$b['confirmation']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->postJson("/account/bookings/{$b['confirmation']}/cancel")->assertStatus(422);
    }

    public function test_members_only_rates_are_hidden_from_non_members(): void
    {
        $this->seedInventory(rooms: 1, rate: 40_000);
        \App\Modules\Reservations\Domain\RatePlan::create(['code' => 'MEM', 'name' => 'Members', 'members_only' => true]);
        [$a] = $this->member('nomember@test.cm', enrol: false);
        $this->loginAs($a);
        $codes = collect($this->postJson('/account/book/search', ['arrival' => now()->addDays(5)->toDateString(), 'departure' => now()->addDays(6)->toDateString()])
            ->assertOk()->json('results.0.offers'))->pluck('rate_plan.code');
        $this->assertNotContains('MEM', $codes->all());
    }

    public function test_one_member_cannot_read_or_change_another_members_data(): void
    {
        [$a] = $this->member('a@test.cm', 'Alice', 'A');
        [$b, $bGuest] = $this->member('b@test.cm', 'Bob', 'B');
        $res = $this->stay($bGuest, checkIn: true);
        $folio = Folio::where('reservation_id', $res->id)->first();
        $inv = app(FolioService::class)->invoice($folio);
        $this->loginAs($a);

        $this->getJson("/account/bookings/{$res->number}")->assertNotFound();
        $this->postJson("/account/bookings/{$res->number}/cancel")->assertNotFound();
        $this->postJson("/account/bookings/{$res->number}/modify", ['arrival' => now()->toDateString(), 'departure' => now()->addDays(3)->toDateString()])->assertNotFound();
        $this->postJson("/account/bookings/{$res->number}/pay")->assertNotFound();
        $this->get("/account/invoices/{$inv->id}/pdf")->assertNotFound();
        $this->get("/account/receipts/{$folio->id}/pdf")->assertNotFound();
        $this->assertSame([], $this->page('/account/bookings')->json('props.data.upcoming'));
        $this->assertSame('checked_in', $res->fresh()->status);

        $this->loginAs($b);
        $this->getJson("/account/bookings/{$res->number}")->assertOk();
    }

    public function test_members_of_another_group_are_isolated(): void
    {
        [$a] = $this->member('ga@test.cm');
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $ctx = app(TenantContext::class);
        $res2 = $ctx->withoutScope(function () use ($g2, $h2) {
            $ctx = app(TenantContext::class);
            $ctx->set($g2->id, $h2->id, [$h2->id]);
            $guest = \App\Modules\Guests\Domain\Guest::create(['first_name' => 'Zed', 'last_name' => 'Z', 'email' => 'zed@other.cm']);
            $type = \App\Modules\Property\Domain\RoomType::create(['code' => 'STD', 'name' => 'Std', 'max_adults' => 2, 'max_children' => 0, 'base_rate' => 30_000]);
            \App\Modules\Property\Domain\Room::create(['room_type_id' => $type->id, 'number' => '1']);
            $plan = \App\Modules\Reservations\Domain\RatePlan::create(['code' => 'BAR', 'name' => 'BAR']);

            return app(\App\Modules\Reservations\Application\ReservationService::class)->create([
                'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
                'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
            ]);
        });
        $this->loginAs($a);
        $this->getJson("/account/bookings/{$res2->number}")->assertNotFound();
        $hotels = $this->page('/account/hotels')->assertOk()->json('props.data.hotels');
        $this->assertSame([$this->hotel->code], array_column($hotels, 'code'));
        $this->postJson('/account/book', [
            'hotel_code' => 'OTH', 'room_type_id' => 1, 'rate_plan_id' => 1, 'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
        ])->assertNotFound();
    }

    public function test_hotels_directory_lists_every_hotel_of_the_group_with_member_perks(): void
    {
        [$a] = $this->member('dir@test.cm');
        $this->makeHotel('Second', 'SEC', $this->group);
        $this->makeHotel('Foreign', 'FRG');
        $this->loginAs($a);
        $d = $this->page('/account/hotels')->assertOk()->json('props.data');
        $codes = array_column($d['hotels'], 'code');
        sort($codes);
        $this->assertSame(['AKW', 'SEC'], $codes);
        $this->assertTrue($d['accepted']);
        $this->assertContains('welcome_drink', $d['perks']);
        $this->assertSame(1000, $d['earn']['spend_per_point']);
    }

    public function test_invoice_and_receipt_pdfs_for_the_members_own_stay(): void
    {
        [$a, $guest] = $this->member('pdf@test.cm');
        $res = $this->stay($guest, checkIn: true);
        $folio = Folio::where('reservation_id', $res->id)->first();
        $inv = app(FolioService::class)->invoice($folio);
        $this->loginAs($a);

        $docs = $this->getJson("/account/bookings/{$res->number}")->assertOk()->json('documents');
        $this->assertSame($inv->number, $docs['invoices'][0]['number']);
        $this->get("/account/invoices/{$inv->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/account/receipts/{$folio->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_chat_message_reaches_the_staff_inbox_and_replies_come_back(): void
    {
        [$a, $guest] = $this->member('chat@test.cm');
        $this->loginAs($a);
        $this->postJson('/account/chat', ['text' => 'Pouvez-vous garder mes bagages ?'])->assertCreated();

        $ev = app(TenantContext::class)->withoutScope(fn () => StoredEvent::where('type', 'messaging.guest_message')->latest('id')->first());
        $this->assertSame($this->hotel->id, $ev->payload['hotel_id']);
        $this->assertSame($guest->id, $ev->payload['guest_id']);
        $this->assertStringContainsString('bagages', $ev->payload['text']);

        $this->backToStaff();
        $list = $this->getJson('/api/v1/messaging/conversations')->assertOk()->json('data');
        $this->assertSame('web', $list[0]['channel']);
        $this->assertSame(1, $list[0]['unread']);
        $this->assertTrue($list[0]['window_open']);
        $id = $list[0]['id'];
        $detail = $this->getJson("/api/v1/messaging/conversations/$id")->assertOk()->json();
        $this->assertSame('Pouvez-vous garder mes bagages ?', $detail['messages'][0]['body']);
        $this->postJson("/api/v1/messaging/conversations/$id/reply", ['text' => 'Bien sûr, à la réception.'])->assertCreated();
        $this->assertSame(0, \App\Modules\Notifications\Domain\WhatsApp\WhatsAppMessage::where('conversation_id', $id)->where('dry_run', true)->count(), 'never sent through WhatsApp');

        $this->loginAs($a);
        $t = $this->getJson('/account/chat/messages')->assertOk()->json();
        $this->assertSame(['me', 'reception'], array_column($t['messages'], 'from'));
        $this->assertSame('Bien sûr, à la réception.', $t['messages'][1]['body']);
        $this->assertSame([], $this->getJson('/account/chat/messages?after='.$t['last_id'])->json('messages'));
    }

    public function test_chat_threads_are_private_to_each_member(): void
    {
        [$a] = $this->member('ca@test.cm', 'Ann', 'A');
        [$b] = $this->member('cb@test.cm', 'Ben', 'B');
        $this->loginAs($a);
        $this->postJson('/account/chat', ['text' => 'secret of A'])->assertCreated();
        $this->loginAs($b);
        $this->assertSame([], $this->getJson('/account/chat/messages')->json('messages'));
        $this->postJson('/account/chat', ['text' => 'hello from B'])->assertCreated();
        $this->assertSame(2, WhatsAppConversation::withoutGlobalScopes()->where('channel', 'web')->count());
        $this->assertSame(['hello from B'], array_column($this->getJson('/account/chat/messages')->json('messages'), 'body'));
    }

    public function test_service_request_needs_a_stay_and_reaches_the_hotel(): void
    {
        [$a, $guest] = $this->member('req@test.cm');
        $this->seedInventory(rooms: 2);
        $this->loginAs($a);
        $this->postJson('/account/requests', ['category' => 'housekeeping', 'items' => ['towels']])->assertStatus(422);

        $this->backToStaff();
        $this->stay($guest, checkIn: true);
        $this->loginAs($a);
        $out = $this->postJson('/account/requests', ['category' => 'housekeeping', 'items' => ['towels'], 'note' => 'Deux serviettes'])->assertCreated()->json();
        $this->assertContains($out['via'], ['service_requests', 'chat']);
        $this->postJson('/account/requests', ['category' => 'bogus', 'items' => []])->assertStatus(422);
    }

    public function test_profile_language_consent_and_password_change(): void
    {
        [$a, $guest] = $this->member('prof@test.cm');
        $this->loginAs($a);
        $this->postJson('/account/profile', ['locale' => 'en', 'marketing_consent' => true])->assertOk()->assertJsonPath('locale', 'en');
        $this->assertSame('en', $guest->fresh()->language);
        $this->assertTrue((bool) $guest->fresh()->marketing_consent);

        $this->postJson('/account/profile/password', ['current_password' => 'wrong', 'password' => 'Newer-pass-2026', 'password_confirmation' => 'Newer-pass-2026'])->assertStatus(422);
        $this->postJson('/account/profile/password', ['current_password' => self::PASSWORD, 'password' => 'Newer-pass-2026', 'password_confirmation' => 'Newer-pass-2026'])->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Newer-pass-2026', $a->fresh()->password));
    }

    public function test_responses_carry_no_password_or_code_hashes(): void
    {
        [$a] = $this->member('pii@test.cm');
        $this->loginAs($a);
        $body = $this->page('/account/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString('otp_hash', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    public function test_demo_member_login_is_off_unless_enabled_and_limited_to_the_demo_group(): void
    {
        $this->group->update(['slug' => 'opes-demo']);
        [$a] = $this->member('demo@test.cm');
        config(['app.demo_logins' => false]);
        $this->asVisitor();
        $this->post("/demo-member-login/{$a->id}")->assertNotFound();

        config(['app.demo_logins' => true]);
        $this->get('/login')->assertInertia(fn ($p) => $p->has('memberDemoAccounts', 1)->where('tab', 'staff'));
        $this->post("/demo-member-login/{$a->id}")->assertRedirect('/account');
        $this->assertAuthenticatedAs($a, 'member');
        $this->post('/demo-member-login/999999')->assertNotFound();
    }
}

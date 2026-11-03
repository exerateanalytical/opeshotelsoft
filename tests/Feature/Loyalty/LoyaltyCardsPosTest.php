<?php

namespace Tests\Feature\Loyalty;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Application\QrCode;
use App\Modules\Loyalty\Domain\Card;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Transaction;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LoyaltyCardsPosTest extends TestCase
{
    private Outlet $bar;

    private Outlet $restaurant;

    private MenuItem $dish;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk();
        $this->bar = Outlet::create(['code' => 'BAR', 'name' => 'Lobby Bar', 'kind' => 'bar']);
        $this->restaurant = Outlet::create(['code' => 'RST', 'name' => 'Restaurant', 'kind' => 'restaurant']);
        // 23 850 TTC = 20 000 net + 3 850 VAT (19.25 %).
        $this->dish = MenuItem::create(['code' => 'NDOLE', 'name' => 'Ndolé', 'price' => 23_850, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $this->guest = Guest::create(['first_name' => 'Amina', 'last_name' => 'Ngono', 'email' => 'amina@test.cm']);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
    }

    private function enrol(string $kind = 'physical', ?Guest $guest = null): array
    {
        return $this->postJson('/api/v1/loyalty/members', ['guest_id' => ($guest ?? $this->guest)->id, 'card' => $kind])->assertCreated()->json();
    }

    private function check(?Outlet $outlet = null, int $qty = 1): array
    {
        $c = $this->postJson('/api/v1/pos/checks', ['outlet_id' => ($outlet ?? $this->bar)->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/items", ['menu_item_id' => $this->dish->id, 'quantity' => $qty])->assertCreated();

        return $c;
    }

    public function test_issue_block_unblock_and_replace_card(): void
    {
        $m = $this->enrol();
        $card = $m['cards'][0];
        $this->assertNotNull($card['expires_on']);

        $this->postJson("/api/v1/loyalty/cards/{$card['id']}/block", ['reason' => 'Lost'])->assertOk()->assertJsonPath('status', 'blocked');
        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $card['card_number']])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/card-blocked');
        $this->postJson("/api/v1/loyalty/cards/{$card['id']}/unblock")->assertOk()->assertJsonPath('status', 'active');
        $this->postJson("/api/v1/loyalty/cards/{$card['id']}/block", ['reason' => 'Stolen'])->assertOk();

        $new = $this->postJson("/api/v1/loyalty/cards/{$card['id']}/replace")->assertCreated()->json();
        $this->assertNotSame($card['card_number'], $new['card_number']);
        $this->assertSame('physical', $new['kind']);
        $old = Card::find($card['id']);
        $this->assertSame('blocked', $old->status, 'a stolen card stays blocked');
        $this->assertSame($new['id'], $old->replaced_by_card_id);
        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $new['card_number']])->assertOk()->assertJsonPath('number', $m['number']);
        $this->postJson("/api/v1/loyalty/cards/{$card['id']}/replace")->assertStatus(422);
    }

    public function test_scan_lookup_by_qr_token_and_digital_card_with_qr(): void
    {
        $m = $this->enrol('digital');
        $card = Card::where('loyalty_membership_id', $m['id'])->first();
        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $card->token])->assertOk()
            ->assertJsonPath('number', $m['number'])->assertJsonPath('tier.code', 'SILVER');

        $res = $this->getJson("/api/v1/loyalty/cards/{$card->id}")->assertOk()->assertJsonPath('usable', true);
        $this->assertStringStartsWith('<svg', $res->json('qr_svg'));

        // 48-char token → version 3 (29 × 29) with the three finder patterns.
        $q = QrCode::matrix($card->token);
        $this->assertCount(29, $q);
        foreach ([[0, 0], [0, 22], [22, 0]] as [$r, $c]) {
            $this->assertTrue($q[$r][$c] && $q[$r + 6][$c + 6] && $q[$r + 3][$c + 3]);
            $this->assertFalse($q[$r + 1][$c + 1]);
        }
        $this->assertTrue($q[21][8], 'dark module');
    }

    public function test_card_pdf_is_rendered(): void
    {
        $m = $this->enrol();
        $res = $this->get("/api/v1/loyalty/cards/{$m['cards'][0]['id']}/pdf?lang=en")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_pos_earn_after_card_scan_on_check(): void
    {
        $m = $this->enrol();
        $c = $this->check(qty: 2);
        $this->postJson("/api/v1/loyalty/pos/checks/{$c['id']}/member", ['card' => $m['cards'][0]['card_number']])
            ->assertOk()->assertJsonPath('member.number', $m['number']);
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 47_700]]])->assertOk();

        $this->assertSame(40, Membership::find($m['id'])->points_balance, '40 000 net → 40 points');
        $b = $this->assertBooksBalance();
        $this->assertSame(-400, $b['419800']);
        $this->assertSame(400, $b['623800']);
    }

    public function test_pos_member_search_attach_and_redeem_points_as_tender_with_balanced_gl(): void
    {
        $m = $this->enrol();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 3_000, 'reason' => 'Goodwill'])->assertCreated();
        $found = $this->getJson('/api/v1/loyalty/members?q=ngono')->json('data.0.id');
        $c = $this->check();
        $this->postJson("/api/v1/loyalty/pos/checks/{$c['id']}/member", ['member_id' => $found])->assertOk()->assertJsonPath('member.points_value', 30_000);

        // 23 850: 20 000 in points (2 000 pts) + 3 850 cash.
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [
            ['method' => 'PTS', 'amount' => 20_000], ['method' => 'CASH', 'amount' => 3_850],
        ]])->assertOk()->assertJsonPath('status', 'settled');

        $member = Membership::find($m['id']);
        // 3 000 - 2 000 redeemed + 3 points earned on the cash part (20 000 net × 3 850 / 23 850 = 3 228 → 3).
        $this->assertSame(1_003, $member->points_balance);
        $this->assertTrue(Transaction::where(['loyalty_membership_id' => $m['id'], 'kind' => 'redeem', 'points' => -2_000, 'source_id' => (string) $c['id']])->exists());

        $b = $this->assertBooksBalance();
        $this->assertSame(-30_000 + 20_000 - 30, $b['419800'], 'liability: earned − redeemed');
        $this->assertSame(3_850, $b['571100']);
        $this->assertSame(-20_000, $b['706300']);
    }

    public function test_redeem_refused_without_enough_points_and_check_stays_open(): void
    {
        $m = $this->enrol();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 100, 'reason' => 'Goodwill'])->assertCreated();
        $c = $this->check();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'PTS', 'amount' => 23_845, 'reference' => $m['number']], ['method' => 'CASH', 'amount' => 5]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/points-amount');
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'PTS', 'amount' => 20_000, 'reference' => $m['number']], ['method' => 'CASH', 'amount' => 3_850]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/insufficient-points');
        // Without a member identified.
        $c2 = $this->check();
        $this->postJson("/api/v1/pos/checks/{$c2['id']}/settle", ['payments' => [['method' => 'PTS', 'amount' => 20_000], ['method' => 'CASH', 'amount' => 3_850]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/member-required');

        $this->assertSame('open', Check::find($c['id'])->status);
        $this->assertSame(100, Membership::find($m['id'])->points_balance);
        $this->assertBooksBalance();
    }

    public function test_promotion_multiplier_by_outlet_and_date_range(): void
    {
        $m = $this->enrol();
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'Happy bar', 'kind' => 'multiplier', 'multiplier' => 3,
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(), 'outlet_ids' => [$this->bar->id]])->assertCreated();
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'Past', 'kind' => 'multiplier', 'multiplier' => 5,
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->subWeek()->toDateString()])->assertCreated();

        $bar = $this->check($this->bar);
        $this->postJson("/api/v1/loyalty/pos/checks/{$bar['id']}/member", ['member_id' => $m['id']])->assertOk();
        $this->postJson("/api/v1/pos/checks/{$bar['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 23_850]]])->assertOk();
        $this->assertSame(60, Membership::find($m['id'])->points_balance, '20 × 3 at the bar');

        $rst = $this->check($this->restaurant);
        $this->postJson("/api/v1/loyalty/pos/checks/{$rst['id']}/member", ['member_id' => $m['id']])->assertOk();
        $this->postJson("/api/v1/pos/checks/{$rst['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 23_850]]])->assertOk();
        $this->assertSame(80, Membership::find($m['id'])->points_balance, 'no promotion at the restaurant');
        $this->assertBooksBalance();
    }

    public function test_birthday_bonus_once_per_year_and_enrolment_bonus(): void
    {
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'Bienvenue', 'kind' => 'enrolment', 'bonus_points' => 250])->assertCreated();
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'Anniversaire', 'kind' => 'birthday', 'bonus_points' => 500])->assertCreated();
        $this->guest->update(['date_of_birth' => now()->subYears(30)->toDateString()]);
        $other = Guest::create(['first_name' => 'Paul', 'last_name' => 'Eto', 'date_of_birth' => now()->subYears(30)->addDays(3)->toDateString()]);
        $m = $this->enrol();
        $o = $this->enrol(guest: $other);
        $this->assertSame(250, Membership::find($m['id'])->points_balance);

        $this->artisan('loyalty:daily')->assertSuccessful();
        $this->artisan('loyalty:daily')->assertSuccessful();
        $this->assertSame(750, Membership::find($m['id'])->points_balance);
        $this->assertSame(250, Membership::find($o['id'])->points_balance);
        $b = $this->assertBooksBalance();
        $this->assertSame(-10_000, $b['419800'], '(250 + 250 + 500) × 10');
    }

    public function test_card_and_points_expiry(): void
    {
        $m = $this->enrol();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 40, 'reason' => 'Goodwill'])->assertCreated();
        $card = Card::find($m['cards'][0]['id']);
        $card->update(['expires_on' => now()->subDay()->toDateString()]);
        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $card->card_number])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/card-expired');

        $this->artisan('loyalty:daily')->assertSuccessful();
        $this->assertSame('expired', $card->fresh()->status);

        Transaction::where('loyalty_membership_id', $m['id'])->update(['expires_on' => now()->subDay()->toDateString()]);
        $this->artisan('loyalty:expire')->assertSuccessful();
        $this->assertSame(0, Membership::find($m['id'])->points_balance);
    }

    public function test_statement_json_pdf_and_email(): void
    {
        Bus::fake();
        $m = $this->enrol();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 120, 'reason' => 'Geste commercial'])->assertCreated();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => -20, 'reason' => 'Correction'])->assertCreated();

        $s = $this->getJson("/api/v1/loyalty/members/{$m['id']}/statement")->assertOk()->json();
        $this->assertSame(0, $s['opening']);
        $this->assertSame(120, $s['earned']);
        $this->assertSame(20, $s['spent']);
        $this->assertSame(100, $s['closing']);
        $this->assertCount(2, $s['lines']);

        $pdf = $this->get("/api/v1/loyalty/members/{$m['id']}/statement/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->postJson("/api/v1/loyalty/members/{$m['id']}/statement/email")->assertStatus(202)->assertJsonPath('queued', 1);
        $msg = OutboxMessage::where('event', 'loyalty.statement')->first();
        $this->assertSame('amina@test.cm', $msg->recipient);
        $this->assertStringContainsString('100 pts', $msg->body);
        $this->assertStringContainsString($m['number'], $msg->subject);
    }

    public function test_tenant_isolation_of_cards_statements_and_promotions(): void
    {
        $m = $this->enrol();
        $card = Card::find($m['cards'][0]['id']);
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'Ours', 'kind' => 'multiplier', 'multiplier' => 2])->assertCreated();

        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($h2);
        $intruder = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->actAs($intruder, $h2);

        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $card->token])->assertNotFound();
        $this->getJson("/api/v1/loyalty/cards/{$card->id}")->assertNotFound();
        $this->get("/api/v1/loyalty/cards/{$card->id}/pdf")->assertNotFound();
        $this->postJson("/api/v1/loyalty/cards/{$card->id}/block", ['reason' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/loyalty/members/{$m['id']}/statement")->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/loyalty/promotions')->assertOk()->json());
        $this->assertSame('active', $card->fresh()->status);
    }

    public function test_promotions_and_card_actions_need_their_permissions(): void
    {
        $m = $this->enrol();
        $cashier = $this->makeUser($this->group, 'pos-cashier', hotels: [$this->hotel]);
        $this->actAs($cashier, $this->hotel);
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'X', 'kind' => 'multiplier', 'multiplier' => 2])->assertForbidden();
        $this->postJson("/api/v1/loyalty/cards/{$m['cards'][0]['id']}/block", ['reason' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $m['cards'][0]['card_number']])->assertOk();

        $desk = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($desk, $this->hotel);
        $this->postJson("/api/v1/loyalty/cards/{$m['cards'][0]['id']}/block", ['reason' => 'Lost'])->assertOk();
        $this->postJson('/api/v1/loyalty/promotions', ['name' => 'X', 'kind' => 'multiplier', 'multiplier' => 2])->assertForbidden();
    }
}

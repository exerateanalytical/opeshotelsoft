<?php

namespace Tests\Feature\Reservations;

use App\Modules\Guests\Domain\Company;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Domain\PromoCode;
use Illuminate\Support\Facades\DB;

class RevenueOffersTest extends RevenueBaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRevenue(stdRooms: 3, dlxRooms: 1);
    }

    private function promo(array $over = []): void
    {
        $this->postJson('/api/v1/revenue/promo-codes', $over + ['code' => 'welcome10', 'name' => 'Welcome', 'type' => 'percent', 'value' => 10])->assertCreated();
    }

    private function web(array $extra = []): array
    {
        return $extra + [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $this->std->id, 'rate_plan_id' => $this->bar->id,
            'arrival' => $this->day(4), 'departure' => $this->day(6), 'adults' => 1, 'accept_terms' => true,
            'guest' => ['first_name' => 'Awa', 'last_name' => 'Ngono', 'email' => 'awa@example.cm', 'phone' => '+237670000000'],
        ];
    }

    public function test_promo_code_discounts_the_quote_and_is_redeemed_on_the_booking_site(): void
    {
        $this->promo(['max_uses' => 1]);
        $this->assertSame('WELCOME10', PromoCode::first()->code);
        $this->getJson("/api/v1/revenue/quote?room_type_id={$this->std->id}&rate_plan_id={$this->bar->id}&arrival={$this->day(4)}&departure={$this->day(6)}&promo_code=welcome10")
            ->assertOk()->assertJsonPath('total', 90_000)->assertJsonPath('promo.discount', 10_000);

        $slug = $this->group->slug;
        $this->anonymous();
        $b = $this->postJson("/api/v1/booking/$slug/reservations", $this->web(['promo_code' => 'WELCOME10']))->assertCreated()->json();
        $this->assertSame(90_000, $b['total']);
        $this->assertSame(1, DB::table('promo_redemptions')->count());
        $this->assertSame(1, (int) DB::table('promo_codes')->value('uses'));

        $this->postJson("/api/v1/booking/$slug/reservations", $this->web(['promo_code' => 'WELCOME10']))->assertStatus(422);
        $this->assertSame(1, Reservation::count());
    }

    public function test_promo_window_and_room_type_limits(): void
    {
        $this->promo(['code' => 'DLXONLY', 'room_type_ids' => [$this->dlx->id], 'stay_from' => $this->day(30), 'stay_to' => $this->day(40)]);
        $q = "room_type_id={$this->std->id}&rate_plan_id={$this->bar->id}&arrival={$this->day(4)}&departure={$this->day(6)}&promo_code=DLXONLY";
        $this->getJson("/api/v1/revenue/quote?$q")->assertStatus(422);
        $this->getJson("/api/v1/revenue/quote?room_type_id={$this->std->id}&rate_plan_id={$this->bar->id}&arrival={$this->day(4)}&departure={$this->day(6)}&promo_code=NOPE")->assertStatus(422);

        $this->asRole('receptionist');
        $this->postJson('/api/v1/revenue/promo-codes', ['code' => 'X', 'name' => 'x', 'type' => 'percent', 'value' => 5])->assertForbidden();
    }

    public function test_booking_site_flags_the_best_available_rate_and_shows_promo(): void
    {
        RatePlan::create(['code' => 'NR', 'name' => 'Non refundable', 'parent_rate_plan_id' => $this->bar->id, 'derived_adjust_percent' => -15, 'refundable' => false]);
        $slug = $this->group->slug;
        $this->anonymous();
        $url = "/api/v1/booking/$slug/availability?arrival={$this->day(4)}&departure={$this->day(5)}";
        $offers = collect($this->getJson($url)->assertOk()->json('results.0.offers'))->where('room_type.code', 'STD')->values();

        $this->assertCount(2, $offers);
        $nr = $offers->firstWhere('rate_plan.code', 'NR');
        $bar = $offers->firstWhere('rate_plan.code', 'BAR');
        $this->assertSame(42_500, $nr['total']);
        $this->assertTrue($nr['is_best_available']);
        $this->assertFalse($bar['is_best_available']);
        $this->assertSame(42_500, $bar['best_available_total']);

        $this->actAs($this->user, $this->hotel);
        $this->promo(['code' => 'FIVE', 'type' => 'amount', 'value' => 10_000]);
        $this->anonymous();
        $offers = collect($this->getJson("$url&promo_code=FIVE")->json('results.0.offers'))->where('room_type.code', 'STD')->values();
        $this->assertSame(40_000, $offers->firstWhere('rate_plan.code', 'BAR')['total']);
        $this->assertSame(40_000, $offers->firstWhere('rate_plan.code', 'BAR')['total_before_promo'] - 10_000);
        $this->assertSame(32_500, $offers->firstWhere('rate_plan.code', 'NR')['total']);
        $this->assertTrue($offers->firstWhere('rate_plan.code', 'NR')['is_best_available'], 'best is computed on final prices');
        $this->assertFalse($offers->firstWhere('rate_plan.code', 'BAR')['is_best_available']);
    }

    private function contract(array $over = []): array
    {
        $company = Company::create(['name' => 'SONARA']);

        return $this->postJson('/api/v1/revenue/contracts', $over + [
            'company_id' => $company->id, 'code' => 'SONARA', 'name' => 'SONARA 2026', 'parent_rate_plan_id' => $this->bar->id, 'discount_percent' => 20,
            'valid_from' => $this->day(0), 'valid_to' => $this->day(200),
        ])->assertCreated()->json();
    }

    public function test_corporate_allotment_is_held_picked_up_and_priced_at_the_negotiated_rate(): void
    {
        $c = $this->contract();
        $this->postJson("/api/v1/revenue/contracts/{$c['id']}/allotments", ['room_type_id' => $this->std->id, 'rooms' => 1, 'start_date' => $this->day(4), 'end_date' => $this->day(5)])->assertCreated();
        $this->assertSame(1, (int) DB::table('room_type_inventory')->where('room_type_id', $this->std->id)->where('date', $this->day(4))->value('blocked'));

        $av = $this->getJson("/api/v1/revenue/contracts/{$c['id']}/availability?room_type_id={$this->std->id}&arrival={$this->day(4)}&departure={$this->day(6)}")->assertOk()->json();
        $this->assertSame('allotment', $av['source']);

        $slug = $this->group->slug;
        $this->anonymous();
        $offers = $this->getJson("/api/v1/booking/$slug/availability?arrival={$this->day(4)}&departure={$this->day(6)}&corporate_code=sonara")->json('results.0.offers');
        $corp = collect($offers)->first(fn ($o) => isset($o['corporate']) && $o['room_type']['code'] === 'STD');
        $this->assertSame(80_000, $corp['total']);
        $this->assertSame('allotment', $corp['corporate']['source']);
        $this->assertNull(collect($this->getJson("/api/v1/booking/$slug/availability?arrival={$this->day(4)}&departure={$this->day(6)}")->json('results.0.offers'))->first(fn ($o) => isset($o['corporate'])));

        $b = $this->postJson("/api/v1/booking/$slug/reservations", $this->web(['rate_plan_id' => $corp['rate_plan']['id'], 'corporate_code' => 'SONARA']))->assertCreated()->json();
        $this->assertSame(80_000, $b['total']);
        $r = Reservation::where('number', $b['confirmation'])->first();
        $this->assertNotNull($r->room_block_id);
        $this->assertSame($c['company_id'], $r->company_id);
        $this->assertSame(1, (int) DB::table('room_block_allocations')->where('room_block_id', $r->room_block_id)->where('date', $this->day(4))->value('picked_up'));

        // Without the code the private plan stays hidden.
        $this->postJson("/api/v1/booking/$slug/reservations", $this->web(['rate_plan_id' => $corp['rate_plan']['id']]))->assertNotFound();
    }

    public function test_last_room_availability_ignores_restrictions_only_when_enabled(): void
    {
        $this->actAs($this->user, $this->hotel);
        $c = $this->contract();
        $this->putJson('/api/v1/rates', ['rate_plan_id' => $this->bar->id, 'room_type_ids' => [$this->std->id], 'from' => $this->day(8), 'to' => $this->day(8), 'closed_to_arrival' => true])->assertSuccessful();

        $body = ['hotel' => null];
        $payload = fn () => [
            'room_type_id' => $this->std->id, 'arrival' => $this->day(8), 'departure' => $this->day(9),
            'rate_plan_id' => $c['rate_plan_id'], 'corporate_code' => 'SONARA',
        ];
        $this->getJson('/api/v1/revenue/quote?'.http_build_query($payload()))->assertStatus(422);

        $this->patchJson("/api/v1/revenue/contracts/{$c['id']}", ['last_room_availability' => true])->assertSuccessful();
        $this->getJson('/api/v1/revenue/quote?'.http_build_query($payload()))->assertOk()->assertJsonPath('total', 40_000)->assertJsonPath('corporate.last_room_availability', true);

        // Sell the last two Standard rooms, then the last room is still bookable only while one is left.
        $this->book($this->std, 8, 1, ['enforce_restrictions' => false]);
        $this->book($this->std, 8, 1, ['enforce_restrictions' => false]);
        $this->getJson('/api/v1/revenue/quote?'.http_build_query($payload()))->assertOk();
        $this->book($this->std, 8, 1, ['rate_plan_id' => $this->bar->id, 'enforce_restrictions' => false]);
        $this->getJson('/api/v1/revenue/quote?'.http_build_query($payload()))->assertStatus(409);
    }

    public function test_unsold_allotment_rooms_return_to_general_stock(): void
    {
        $c = $this->contract();
        $this->postJson("/api/v1/revenue/contracts/{$c['id']}/allotments", [
            'room_type_id' => $this->std->id, 'rooms' => 2, 'start_date' => $this->day(10), 'end_date' => $this->day(11), 'release_days' => 14,
        ])->assertCreated();
        $blocked = fn () => (int) DB::table('room_type_inventory')->where('room_type_id', $this->std->id)->sum('blocked');
        $this->assertSame(4, $blocked());

        $this->postJson('/api/v1/revenue/allotments/release')->assertOk()->assertJsonPath('rooms_released', 4);
        $this->assertSame(0, $blocked());
        $this->postJson('/api/v1/revenue/allotments/release')->assertOk()->assertJsonPath('rooms_released', 0);
    }

    public function test_allotment_cannot_exceed_free_rooms_or_contract_dates(): void
    {
        $c = $this->contract(['valid_to' => $this->day(20)]);
        $this->postJson("/api/v1/revenue/contracts/{$c['id']}/allotments", ['room_type_id' => $this->std->id, 'rooms' => 4, 'start_date' => $this->day(4), 'end_date' => $this->day(4)])->assertStatus(409);
        $this->postJson("/api/v1/revenue/contracts/{$c['id']}/allotments", ['room_type_id' => $this->std->id, 'rooms' => 1, 'start_date' => $this->day(19), 'end_date' => $this->day(25)])->assertStatus(422);
        $this->assertSame(0, (int) DB::table('room_type_inventory')->sum('blocked'));
    }
}

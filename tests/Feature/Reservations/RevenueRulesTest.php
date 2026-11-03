<?php

namespace Tests\Feature\Reservations;

use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Domain\RatePlan;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;

class RevenueRulesTest extends RevenueBaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRevenue();
    }

    private function quote(RatePlan $plan, int $in, int $nights, int $adults = 2, int $children = 0, bool $enforce = true): array
    {
        return app(Pricing::class)->quote($plan, $this->std, Carbon::today()->addDays($in), Carbon::today()->addDays($in + $nights), $adults, $children, $enforce);
    }

    public function test_plan_without_rules_prices_exactly_as_before(): void
    {
        $q = $this->quote($this->bar, 5, 3);
        $this->assertSame(150_000, $q['total']);
        $this->assertSame($q, $this->quote($this->bar, 5, 3), 'pricing is deterministic');
    }

    public function test_length_of_stay_rule_blocks_short_and_long_stays(): void
    {
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/rules", ['min_los' => 2, 'max_los' => 4])->assertSuccessful();

        $this->assertSame(100_000, $this->quote($this->bar, 5, 2)['total']);
        $this->assertSame(200_000, $this->quote($this->bar, 5, 4)['total']);
        $this->assertSame(50_000, $this->quote($this->bar, 5, 1, enforce: false)['total'], 'staff override skips the rules');
        foreach ([1, 5] as $nights) {
            try {
                $this->quote($this->bar, 5, $nights);
                $this->fail("$nights nights should be refused");
            } catch (DomainException $e) {
                $this->assertSame('restricted', $e->problem);
            }
        }
    }

    public function test_max_los_rejected_over_the_api(): void
    {
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/rules", ['max_los' => 2])->assertSuccessful();
        $this->postJson('/api/v1/reservations', [
            'guest_id' => $this->guest->id, 'room_type_id' => $this->std->id, 'rate_plan_id' => $this->bar->id,
            'arrival' => $this->day(5), 'departure' => $this->day(8),
        ])->assertStatus(422);
    }

    public function test_advance_purchase_window(): void
    {
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/rules", ['advance_min_days' => 7, 'advance_max_days' => 60])->assertSuccessful();

        $this->assertSame(50_000, $this->quote($this->bar, 7, 1)['total']);
        foreach ([3, 61] as $in) {
            try {
                $this->quote($this->bar, $in, 1);
                $this->fail("advance $in should be refused");
            } catch (DomainException $e) {
                $this->assertSame('restricted', $e->problem);
            }
        }
    }

    public function test_closed_arrival_weekdays(): void
    {
        $dow = Carbon::today()->addDays(9)->dayOfWeek;
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/rules", ['closed_arrival_days' => [$dow]])->assertSuccessful();

        $this->expectException(DomainException::class);
        $this->quote($this->bar, 9, 1);
    }

    public function test_package_per_room_and_per_person_pricing(): void
    {
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/package", [
            'name' => 'Romantic', 'pricing' => 'per_room', 'room_amount' => 10_000,
            'inclusions' => [['name' => 'Breakfast', 'qty' => 2], ['name' => 'Spa access']],
        ])->assertSuccessful()->assertJsonPath('inclusions.0.name', 'Breakfast');
        $this->assertSame(2 * 60_000, $this->quote($this->bar, 5, 2)['total']);

        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/package", [
            'name' => 'Half board', 'pricing' => 'per_person', 'adult_amount' => 7_000, 'child_amount' => 3_000,
        ])->assertSuccessful();
        // 2 adults + 1 child: 50000 + 14000 + 3000 per night
        $this->assertSame(2 * 67_000, $this->quote($this->bar, 5, 2, 2, 1)['total']);

        $this->deleteJson("/api/v1/revenue/rate-plans/{$this->bar->id}/package")->assertNoContent();
        $this->assertSame(100_000, $this->quote($this->bar, 5, 2)['total']);
    }

    public function test_derived_plan_with_percent_and_fixed_amount(): void
    {
        $derived = RatePlan::create(['code' => 'EARLY', 'name' => 'Early bird', 'parent_rate_plan_id' => $this->bar->id, 'derived_adjust_percent' => -10]);
        $this->assertSame(45_000, $this->quote($derived, 5, 1)['total']);

        $this->putJson("/api/v1/revenue/rate-plans/{$derived->id}/rules", ['derived_adjust_amount' => 2_000])->assertSuccessful();
        $this->assertSame(47_000, $this->quote($derived, 5, 1)['total']);

        $rates = $this->getJson("/api/v1/rates?from={$this->day(5)}&to={$this->day(5)}&rate_plan_id={$derived->id}&room_type_id={$this->std->id}")->assertOk()->json();
        $this->assertSame(47_000, $rates[0]['amount'], 'rate grid matches the quote');
    }

    public function test_rules_need_the_manage_permission(): void
    {
        $this->asRole('receptionist');
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/rules", ['min_los' => 2])->assertForbidden();
        $this->asRole('revenue-manager');
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/rules", ['min_los' => 2])->assertSuccessful();
    }

    public function test_quote_endpoint_returns_package_and_total(): void
    {
        $this->putJson("/api/v1/revenue/rate-plans/{$this->bar->id}/package", ['name' => 'BB', 'pricing' => 'per_room', 'room_amount' => 5_000, 'inclusions' => [['name' => 'Breakfast']]])->assertSuccessful();
        $this->getJson("/api/v1/revenue/quote?room_type_id={$this->std->id}&rate_plan_id={$this->bar->id}&arrival={$this->day(4)}&departure={$this->day(6)}&adults=2")
            ->assertSuccessful()->assertJsonPath('total', 110_000)->assertJsonPath('package.per_night', 5_000);
    }
}

<?php

namespace Tests\Feature\Reservations;

use App\Modules\Reservations\Revenue\Application\RevenueConfig;
use App\Modules\Reservations\Revenue\Application\SuggestionEngine;
use App\Modules\Reservations\Revenue\Domain\RateSuggestion;
use App\Platform\Events\StoredEvent;
use Illuminate\Support\Facades\DB;

class RevenueSuggestionsTest extends RevenueBaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRevenue();
    }

    private array $settings = [
        'high_occupancy_pct' => 85, 'low_occupancy_pct' => 40, 'high_occupancy_uplift' => 10, 'low_occupancy_discount' => 10,
        'pace_threshold_pct' => 15, 'pace_uplift' => 5, 'weekend_uplift' => 5, 'weekend_days' => [5, 6],
        'competitor_undercut_pct' => 0, 'max_change_pct' => 25, 'rounding' => 500,
    ];

    private function in(array $over = []): array
    {
        return $over + ['current' => 50_000, 'occupancy_pct' => 60, 'pace_pct' => 0, 'dow' => 2, 'events' => [], 'competitor_avg' => null];
    }

    public function test_engine_is_deterministic_and_caps_the_change(): void
    {
        $e = new SuggestionEngine;
        $in = $this->in(['occupancy_pct' => 90, 'pace_pct' => 20, 'dow' => 5, 'events' => [['name' => 'Festival', 'impact_pct' => 10]]]);
        $a = $e->evaluate($this->settings, $in);

        $this->assertSame($a, $e->evaluate($this->settings, $in));
        $this->assertSame(62_500, $a['suggested'], '10+5+5+10 = 30% capped at 25%');
        $this->assertSame(25.0, $a['change_pct']);
        $this->assertSame(['occupancy', 'pace', 'weekday', 'event'], array_column($a['reasons'], 'factor'));
    }

    public function test_engine_discounts_low_demand_and_follows_competitors(): void
    {
        $e = new SuggestionEngine;
        $this->assertSame(45_000, $e->evaluate($this->settings, $this->in(['occupancy_pct' => 20]))['suggested']);
        $this->assertSame(50_000, $e->evaluate($this->settings, $this->in())['suggested'], 'nothing to say: unchanged');

        $c = $e->evaluate($this->settings, $this->in(['competitor_avg' => 70_000]));
        $this->assertSame(60_000, $c['suggested'], 'moves halfway to the market');
        $this->assertSame('competitor', $c['reasons'][0]['factor']);
    }

    public function test_generate_never_changes_a_rate_and_accept_writes_it(): void
    {
        // Both Standard rooms sold on the night 10 days ahead = 100% occupancy.
        $this->book($this->std, 10, 1);
        $this->book($this->std, 10, 1);
        $date = $this->day(10);

        $before = DB::table('rates')->count();
        $res = $this->postJson('/api/v1/revenue/suggestions/generate', ['from' => $date, 'to' => $date, 'room_type_id' => $this->std->id])->assertCreated()->json('data');
        $this->assertSame($before, DB::table('rates')->count(), 'generating changes no rate');
        $this->assertCount(1, $res);
        $this->assertSame('pending', $res[0]['status']);
        $this->assertGreaterThan(50_000, $res[0]['suggested_rate']);
        $this->assertSame('occupancy', $res[0]['reasons'][0]['factor']);

        // Still the old price on the rate grid and in quotes.
        $this->getJson("/api/v1/revenue/quote?room_type_id={$this->std->id}&rate_plan_id={$this->bar->id}&arrival=$date&departure={$this->day(11)}")->assertJsonPath('total', 50_000);

        $id = $res[0]['id'];
        $this->postJson("/api/v1/revenue/suggestions/$id/accept", ['rate' => 58_000, 'note' => 'rounded'])->assertSuccessful()->assertJsonPath('status', 'accepted');
        $this->assertSame(58_000, (int) DB::table('rates')->where(['rate_plan_id' => $this->bar->id, 'room_type_id' => $this->std->id, 'date' => $date])->value('amount'));
        $this->getJson("/api/v1/revenue/quote?room_type_id={$this->std->id}&rate_plan_id={$this->bar->id}&arrival=$date&departure={$this->day(11)}")->assertJsonPath('total', 58_000);
        $this->assertSame(1, StoredEvent::where('type', 'rates.updated')->count(), 'channels are told');
        $this->assertSame(1, StoredEvent::where('type', 'revenue.suggestion.accepted')->count());

        $this->postJson("/api/v1/revenue/suggestions/$id/accept")->assertStatus(409);
        $this->postJson("/api/v1/revenue/suggestions/$id/reject")->assertStatus(409);
    }

    public function test_reject_keeps_the_rate_and_a_new_run_supersedes_pending(): void
    {
        $this->book($this->std, 12, 1);
        $this->book($this->std, 12, 1);
        $date = $this->day(12);
        $first = $this->postJson('/api/v1/revenue/suggestions/generate', ['from' => $date, 'to' => $date, 'room_type_id' => $this->std->id])->json('data.0.id');
        $second = $this->postJson('/api/v1/revenue/suggestions/generate', ['from' => $date, 'to' => $date, 'room_type_id' => $this->std->id])->json('data.0.id');

        $this->assertSame('superseded', RateSuggestion::find($first)->status);
        $this->postJson("/api/v1/revenue/suggestions/$second/reject", ['note' => 'no'])->assertSuccessful()->assertJsonPath('status', 'rejected');
        $this->assertSame(0, DB::table('rates')->count());
        $this->getJson('/api/v1/revenue/suggestions?status=pending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_events_and_competitor_rates_feed_the_suggestion(): void
    {
        $date = $this->day(15);
        $this->postJson('/api/v1/revenue/events', ['name' => 'Douala trade fair', 'start_date' => $date, 'end_date' => $date, 'impact_pct' => 20])->assertCreated();
        $this->postJson('/api/v1/revenue/competitor-rates', ['competitor' => 'Hotel Sawa', 'date' => $date, 'rate' => 60_000])->assertCreated();
        $this->postJson('/api/v1/revenue/competitor-rates', ['competitor' => 'Hotel Sawa', 'date' => $date, 'rate' => 64_000])->assertCreated();
        $this->assertSame(1, DB::table('competitor_rates')->count(), 'same competitor and date is updated');

        $sug = $this->postJson('/api/v1/revenue/suggestions/generate', ['from' => $date, 'to' => $date, 'room_type_id' => $this->std->id, 'rate_plan_id' => $this->bar->id])
            ->assertCreated()->json('data.0');
        $factors = array_column($sug['reasons'], 'factor');
        $this->assertContains('event', $factors);
        $this->assertContains('competitor', $factors);
        $this->assertSame(24.0, (float) $sug['change_pct'], '20% event - 10% empty hotel + 14% competitor');
    }

    public function test_only_approvers_can_decide_and_settings_are_validated(): void
    {
        $this->book($this->std, 10, 1);
        $this->book($this->std, 10, 1);
        $id = $this->postJson('/api/v1/revenue/suggestions/generate', ['from' => $this->day(10), 'to' => $this->day(10), 'room_type_id' => $this->std->id])->json('data.0.id');

        $this->asRole('front-office-manager');
        $this->postJson("/api/v1/revenue/suggestions/$id/accept")->assertForbidden();
        $this->getJson('/api/v1/revenue/suggestions')->assertOk();
        $this->putJson('/api/v1/revenue/settings', ['max_change_pct' => 10])->assertForbidden();

        $this->asRole('revenue-manager');
        $this->putJson('/api/v1/revenue/settings', ['max_change_pct' => 500])->assertStatus(422);
        $this->putJson('/api/v1/revenue/settings', ['max_change_pct' => 10, 'rounding' => 1000])->assertSuccessful()->assertJsonPath('max_change_pct', 10);
        $this->assertSame(1000, app(RevenueConfig::class)->get()->rounding);
        $this->postJson("/api/v1/revenue/suggestions/$id/accept")->assertSuccessful();
    }
}

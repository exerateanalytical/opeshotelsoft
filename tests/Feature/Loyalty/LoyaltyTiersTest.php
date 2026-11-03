<?php

namespace Tests\Feature\Loyalty;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Domain\Tier;
use Tests\TestCase;

class LoyaltyTiersTest extends TestCase
{
    private function setupProgram(): void
    {
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk();
    }

    public function test_list_create_and_update_tiers(): void
    {
        $this->setupProgram();
        $this->getJson('/api/v1/loyalty/tiers')->assertOk()->assertJsonCount(3);

        $t = $this->postJson('/api/v1/loyalty/tiers', [
            'code' => 'diam', 'name' => 'Diamond', 'min_qualifying_points' => 40_000, 'min_qualifying_nights' => 60,
            'earn_multiplier' => 2, 'benefits' => ['butler'], 'rank' => 4,
        ])->assertCreated()->assertJsonPath('code', 'DIAM')->json();

        $this->patchJson("/api/v1/loyalty/tiers/{$t['id']}", ['name' => 'Black', 'earn_multiplier' => 2.5, 'benefits' => ['butler', 'spa']])
            ->assertOk()->assertJsonPath('name', 'Black')->assertJsonPath('benefits.1', 'spa');
        $this->patchJson("/api/v1/loyalty/tiers/{$t['id']}", ['active' => false])->assertOk()->assertJsonPath('active', false);
    }

    public function test_thresholds_must_strictly_increase_with_sort_order(): void
    {
        $this->setupProgram();
        // Rank 4 below Platinum's 15 000 points.
        $this->postJson('/api/v1/loyalty/tiers', ['code' => 'X', 'name' => 'X', 'min_qualifying_points' => 10_000, 'rank' => 4])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/loyalty-tier-thresholds');
        $this->assertFalse(Tier::where('code', 'X')->exists());

        $gold = Tier::where('code', 'GOLD')->first();
        $this->patchJson("/api/v1/loyalty/tiers/{$gold->id}", ['min_qualifying_points' => 15_000])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/loyalty-tier-thresholds');
        $this->assertSame(5_000, $gold->fresh()->min_qualifying_points);
    }

    public function test_tier_with_members_cannot_be_deactivated(): void
    {
        $this->setupProgram();
        [, , $guest] = $this->seedInventory();
        $this->postJson('/api/v1/loyalty/members', ['guest_id' => $guest->id])->assertCreated();
        $silver = Tier::where('code', 'SILVER')->first();

        $this->patchJson("/api/v1/loyalty/tiers/{$silver->id}", ['active' => false])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/loyalty-tier-has-members');
        $this->assertTrue($silver->fresh()->active);
    }

    public function test_tier_filter_and_search_combine(): void
    {
        $this->setupProgram();
        [, , $g1] = $this->seedInventory();
        $g1->update(['first_name' => 'Amina', 'last_name' => 'Ngono']);
        $g2 = Guest::create(['first_name' => 'Amina', 'last_name' => 'Fotso']);
        $g3 = Guest::create(['first_name' => 'Paul', 'last_name' => 'Aminou']);
        $m1 = $this->postJson('/api/v1/loyalty/members', ['guest_id' => $g1->id])->assertCreated()->json();
        $this->postJson('/api/v1/loyalty/members', ['guest_id' => $g2->id])->assertCreated();
        $m3 = $this->postJson('/api/v1/loyalty/members', ['guest_id' => $g3->id])->assertCreated()->json();
        $gold = Tier::where('code', 'GOLD')->first();
        \App\Modules\Loyalty\Domain\Membership::whereKey([$m1['id'], $m3['id']])->update(['loyalty_tier_id' => $gold->id]);

        // "amin" matches all three (two first names, one last name); GOLD narrows to m1 and m3.
        $ids = collect($this->getJson('/api/v1/loyalty/members?tier=GOLD&q=amin')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$m1['id'], $m3['id']], $ids);
        $this->assertCount(1, $this->getJson('/api/v1/loyalty/members?tier=SILVER&q=amin')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/loyalty/members?tier=PLAT&q=amin')->json('data'));
    }
}

<?php

namespace Tests\Feature\Property;

use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use Tests\TestCase;

class PropertyApiTest extends TestCase
{
    public function test_bulk_rooms_reports_created_and_skipped_without_changing_existing_type(): void
    {
        [$dlx] = $this->seedInventory(rooms: 2); // rooms 101, 102 of DLX
        $std = RoomType::create(['code' => 'STD', 'name' => 'Standard', 'max_adults' => 2, 'max_children' => 0, 'base_rate' => 30_000]);

        $res = $this->postJson('/api/v1/rooms/bulk', ['room_type_id' => $std->id, 'from' => 101, 'to' => 104])
            ->assertCreated()->json();

        $this->assertSame(['103', '104'], $res['created']);
        $this->assertSame([
            ['number' => '101', 'reason' => 'exists with type DLX'],
            ['number' => '102', 'reason' => 'exists with type DLX'],
        ], $res['skipped']);
        $this->assertSame($dlx->id, Room::where('number', '101')->first()->room_type_id);
    }

    public function test_rates_fill_unsaved_nights_with_effective_price(): void
    {
        [$type, $plan] = $this->seedInventory(rate: 50_000);
        $derived = RatePlan::create(['code' => 'NR', 'name' => 'Non refundable', 'parent_rate_plan_id' => $plan->id, 'derived_adjust_percent' => -10]);
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(4)->toDateString();
        $this->putJson('/api/v1/rates', ['rate_plan_id' => $plan->id, 'room_type_ids' => [$type->id], 'from' => $d1, 'to' => $d1, 'amount' => 70_000])->assertOk();

        $bar = collect($this->getJson("/api/v1/rates?from=$d1&to=$d2&rate_plan_id={$plan->id}")->assertOk()->json())->keyBy(fn ($r) => substr($r['date'], 0, 10));
        $this->assertSame([70_000, 'override'], [$bar[$d1]['amount'], $bar[$d1]['source']]);
        $this->assertSame([50_000, 'base'], [$bar[$d2]['amount'], $bar[$d2]['source']]);

        $nr = collect($this->getJson("/api/v1/rates?from=$d1&to=$d2&rate_plan_id={$derived->id}")->json())->keyBy(fn ($r) => substr($r['date'], 0, 10));
        $this->assertSame([63_000, 'base'], [$nr[$d1]['amount'], $nr[$d1]['source']]);
        $this->assertSame(45_000, $nr[$d2]['amount']);
    }
}

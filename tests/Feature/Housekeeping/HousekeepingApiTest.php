<?php

namespace Tests\Feature\Housekeeping;

use App\Modules\Housekeeping\Domain\HkTask;
use App\Modules\Housekeeping\Domain\LostFoundItem;
use App\Modules\Property\Domain\Room;
use Tests\TestCase;

class HousekeepingApiTest extends TestCase
{
    public function test_assign_requires_staff_of_current_hotel(): void
    {
        $this->seedInventory(rooms: 1);
        $task = HkTask::create(['room_id' => Room::first()->id, 'date' => now()->toDateString()]);
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $elsewhere = $this->makeUser($this->group, 'room-attendant', hotels: [$other]);
        $here = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);

        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/assign", ['user_id' => $elsewhere->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/assign", ['user_id' => 999999])->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/assign", ['user_id' => $here->id])->assertOk()->assertJsonPath('assigned_to', $here->id);
    }

    public function test_generate_validates_date(): void
    {
        $this->postJson('/api/v1/housekeeping/tasks/generate', ['date' => 'not-a-date'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/housekeeping/tasks/generate', [])->assertOk();
    }

    public function test_lost_found_filters_found_on_and_dispose(): void
    {
        $this->postJson('/api/v1/housekeeping/lost-found', ['description' => 'Black umbrella', 'found_on' => now()->addDay()->toDateString()])->assertStatus(422);
        $old = $this->postJson('/api/v1/housekeeping/lost-found', ['description' => 'Black umbrella', 'location' => 'Lobby', 'found_on' => now()->subDays(10)->toDateString()])->assertCreated()->json();
        $this->assertSame(now()->subDays(10)->toDateString(), substr($old['found_on'], 0, 10));
        $new = $this->postJson('/api/v1/housekeeping/lost-found', ['description' => 'Phone charger', 'location' => 'Pool bar'])->assertCreated()->json();

        $ids = fn (string $qs) => array_column($this->getJson("/api/v1/housekeeping/lost-found?$qs")->assertOk()->json('data'), 'id');
        $this->assertSame([$old['id']], $ids('q=umbrella'));
        $this->assertSame([$new['id']], $ids('q=pool'));
        $this->assertSame([$old['id']], $ids('to='.now()->subDays(5)->toDateString()));
        $this->assertSame([$new['id']], $ids('from='.now()->subDay()->toDateString()));

        $this->postJson("/api/v1/housekeeping/lost-found/{$old['id']}/dispose", [])->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/lost-found/{$old['id']}/dispose", ['reason' => 'Unclaimed 90 days'])->assertOk()->assertJsonPath('status', 'disposed');
        $this->postJson("/api/v1/housekeeping/lost-found/{$old['id']}/dispose", ['reason' => 'again'])->assertStatus(422);
        $this->assertSame([$old['id']], $ids('status=disposed'));
        $this->assertSame([$new['id']], $ids('status=stored'));
        $this->assertSame('Unclaimed 90 days', LostFoundItem::find($old['id'])->disposal_reason);
    }
}

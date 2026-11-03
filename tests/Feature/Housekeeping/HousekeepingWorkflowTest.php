<?php

namespace Tests\Feature\Housekeeping;

use App\Modules\Housekeeping\Domain\HkTask;
use App\Modules\Property\Domain\Room;
use Tests\TestCase;

class HousekeepingWorkflowTest extends TestCase
{
    public function test_generate_creates_tasks_for_dirty_rooms_idempotently(): void
    {
        $this->seedInventory(rooms: 3);
        Room::where('number', '101')->update(['hk_status' => 'vacant_dirty']);
        Room::where('number', '102')->update(['hk_status' => 'vacant_dirty']);

        $this->postJson('/api/v1/housekeeping/tasks/generate')->assertOk()->assertJsonPath('created', 2);
        $this->postJson('/api/v1/housekeeping/tasks/generate')->assertOk()->assertJsonPath('created', 0);
        $this->assertCount(2, $this->getJson('/api/v1/housekeeping/tasks')->assertOk()->json());
    }

    public function test_start_finish_inspect_moves_room_to_inspected(): void
    {
        $this->seedInventory(rooms: 1);
        $room = Room::first();
        $room->update(['hk_status' => 'vacant_dirty']);
        $task = HkTask::create(['room_id' => $room->id, 'date' => now()->toDateString()]);

        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/inspect", ['passed' => true])->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/finish")->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/start")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/start")->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/finish", ['notes' => 'ok'])->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame('vacant_clean', $room->fresh()->hk_status);
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/inspect", ['passed' => true])->assertOk()->assertJsonPath('status', 'inspected');
        $this->assertSame('inspected', $room->fresh()->hk_status);
    }

    public function test_failed_inspection_returns_room_to_dirty(): void
    {
        $this->seedInventory(rooms: 1);
        $room = Room::first();
        $task = HkTask::create(['room_id' => $room->id, 'date' => now()->toDateString()]);
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/start")->assertOk();
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/finish")->assertOk();
        $this->postJson("/api/v1/housekeeping/tasks/{$task->id}/inspect", ['passed' => false, 'notes' => 'Dust'])->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame('vacant_dirty', $room->fresh()->hk_status);
    }

    public function test_room_status_rejects_unknown_and_vacant_on_occupied(): void
    {
        $this->seedInventory(rooms: 1);
        $room = Room::first();
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/status", ['status' => 'sparkly'])->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/status", ['status' => 'out_of_order', 'reason' => 'Leak'])->assertOk()->assertJsonPath('hk_status', 'out_of_order');
        $room->update(['occupied' => true]);
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/status", ['status' => 'vacant_clean'])->assertStatus(422);
        $this->assertSame('out_of_order', $room->fresh()->hk_status);
    }

    public function test_attendant_sees_only_own_tasks_and_cannot_touch_others(): void
    {
        $this->seedInventory(rooms: 2);
        [$r1, $r2] = Room::orderBy('number')->get()->all();
        $me = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $other = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $mine = HkTask::create(['room_id' => $r1->id, 'date' => now()->toDateString(), 'assigned_to' => $me->id]);
        $theirs = HkTask::create(['room_id' => $r2->id, 'date' => now()->toDateString(), 'assigned_to' => $other->id]);

        $this->actAs($me, $this->hotel);
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/housekeeping/tasks')->assertOk()->json(), 'id'));
        $this->postJson("/api/v1/housekeeping/tasks/{$theirs->id}/start")->assertForbidden();
        $this->postJson('/api/v1/housekeeping/tasks/generate')->assertForbidden();
        $this->postJson("/api/v1/housekeeping/tasks/{$mine->id}/start")->assertOk();
    }
}

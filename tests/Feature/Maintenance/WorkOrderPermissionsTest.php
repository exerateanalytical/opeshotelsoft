<?php

namespace Tests\Feature\Maintenance;

use App\Modules\Property\Domain\Room;
use Tests\TestCase;

class WorkOrderPermissionsTest extends TestCase
{
    private const BASE = '/api/v1/maintenance/work-orders';

    public function test_store_validates_category_priority_and_title(): void
    {
        $this->postJson(self::BASE, [])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->postJson(self::BASE, ['title' => 'X', 'category' => 'magic'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->postJson(self::BASE, ['title' => 'X', 'priority' => 'asap'])->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->postJson(self::BASE, ['title' => 'X', 'out_of_order_until' => now()->subDay()->toDateString()])->assertStatus(422);
    }

    public function test_attendant_cannot_take_room_out_of_order_or_work_orders(): void
    {
        $this->seedInventory(rooms: 1);
        $room = Room::first();
        $wo = $this->postJson(self::BASE, ['title' => 'Prise cassee'])->assertCreated()->json();
        $this->actAs($this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]), $this->hotel);

        $this->postJson(self::BASE, ['title' => 'Fuite', 'room_id' => $room->id, 'room_out_of_order' => true])->assertForbidden();
        $this->assertNotSame('out_of_order', $room->fresh()->hk_status);
        $this->postJson(self::BASE."/{$wo['id']}/start")->assertForbidden();
        $this->postJson(self::BASE."/{$wo['id']}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_start_then_complete_requires_resolution(): void
    {
        $wo = $this->postJson(self::BASE, ['title' => 'Climatiseur'])->assertCreated()->json();
        $this->postJson(self::BASE."/{$wo['id']}/start")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson(self::BASE."/{$wo['id']}/complete", [])->assertStatus(422)->assertJsonValidationErrors('resolution');
        $this->postJson(self::BASE."/{$wo['id']}/complete", ['resolution' => 'Gaz recharge', 'minutes' => 45])->assertOk()->assertJsonPath('status', 'done');
        $this->postJson(self::BASE."/{$wo['id']}/cancel", ['reason' => 'late'])->assertStatus(422);
    }
}

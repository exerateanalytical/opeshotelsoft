<?php

namespace Tests\Feature\Maintenance;

use App\Modules\Maintenance\Domain\PmSchedule;
use App\Modules\Property\Domain\Asset;
use App\Modules\Property\Domain\Room;
use Tests\TestCase;

class WorkOrderLifecycleTest extends TestCase
{
    private const BASE = '/api/v1/maintenance/work-orders';

    private function open(array $data = []): array
    {
        return $this->postJson(self::BASE, $data + ['title' => 'Ampoule grillée'])->assertCreated()->json();
    }

    public function test_index_filters_by_priority_and_search_and_paginates(): void
    {
        $tech = $this->makeUser($this->group, 'maintenance-technician', hotels: [$this->hotel]);
        $this->open(['title' => 'Fuite lavabo', 'priority' => 'urgent', 'assigned_to' => $tech->id]);
        $this->open(['title' => 'Climatiseur bruyant', 'description' => 'compresseur', 'priority' => 'low']);
        $this->open(['title' => 'Porte', 'priority' => 'low']);

        $r = $this->getJson(self::BASE.'?priority=urgent')->assertOk()->json();
        $this->assertSame(1, $r['total']);
        $this->assertSame($tech->name, $r['data'][0]['assignee']['name']);

        $this->assertSame(1, $this->getJson(self::BASE.'?q=COMPRESS')->json('total'));
        $this->assertSame(1, $this->getJson(self::BASE.'?q=fuite')->json('total'));

        $p = $this->getJson(self::BASE.'?per_page=2&page=2')->assertOk()->json();
        $this->assertSame(2, $p['last_page']);
        $this->assertCount(1, $p['data']);
        $this->assertSame(200, $this->getJson(self::BASE.'?per_page=9999')->json('per_page'));
    }

    public function test_show_loads_room_asset_and_assignee(): void
    {
        $this->seedInventory(rooms: 1);
        $room = Room::first();
        $asset = Asset::create(['name' => 'Split AC', 'tag' => 'AC-01', 'room_id' => $room->id]);
        $tech = $this->makeUser($this->group, 'maintenance-technician', hotels: [$this->hotel]);
        $wo = $this->open(['room_id' => $room->id, 'asset_id' => $asset->id, 'assigned_to' => $tech->id]);

        $this->getJson(self::BASE."/{$wo['id']}")->assertOk()
            ->assertJsonPath('room.number', $room->number)
            ->assertJsonPath('asset.tag', 'AC-01')->assertJsonPath('asset.name', 'Split AC')
            ->assertJsonPath('assignee.id', $tech->id)->assertJsonPath('assignee.name', $tech->name);
    }

    public function test_hold_resume_and_invalid_transitions(): void
    {
        $wo = $this->open();
        $this->postJson(self::BASE."/{$wo['id']}/start")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson(self::BASE."/{$wo['id']}/hold", ['reason' => 'Pièce commandée'])->assertOk()
            ->assertJsonPath('status', 'on_hold')->assertJsonPath('hold_reason', 'Pièce commandée');

        $this->postJson(self::BASE."/{$wo['id']}/complete", ['resolution' => 'x'])->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/invalid-state');
        $this->postJson(self::BASE."/{$wo['id']}/hold", ['reason' => 'again'])->assertStatus(422);

        $this->postJson(self::BASE."/{$wo['id']}/resume")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson(self::BASE."/{$wo['id']}/resume")->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/invalid-state');

        $this->postJson(self::BASE."/{$wo['id']}/complete", ['resolution' => 'ok', 'labour_cost' => 15_000])->assertOk()
            ->assertJsonPath('status', 'done')->assertJsonPath('labour_cost', 15_000);
        $this->postJson(self::BASE."/{$wo['id']}/cancel", ['reason' => 'x'])->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/invalid-state');
        $this->postJson(self::BASE."/{$wo['id']}/start")->assertStatus(422);
    }

    public function test_cancel_lifts_out_of_order_room(): void
    {
        $this->seedInventory(rooms: 2);
        $room = Room::first();
        $today = now()->toDateString();
        $wo = $this->open(['room_id' => $room->id, 'room_out_of_order' => true, 'out_of_order_until' => now()->addDays(2)->toDateString()]);
        $this->assertSame(1, $this->getJson("/api/v1/inventory?from=$today&to=$today")->json('0.days.0.available'));

        $this->postJson(self::BASE."/{$wo['id']}/cancel", ['reason' => 'Signalé par erreur'])->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('cancel_reason', 'Signalé par erreur');
        $this->assertSame('vacant_dirty', $room->fresh()->hk_status);
        $this->assertSame(2, $this->getJson("/api/v1/inventory?from=$today&to=$today")->json('0.days.0.available'));
    }

    public function test_schedule_can_be_updated_and_deactivated(): void
    {
        $s = $this->postJson('/api/v1/maintenance/schedules', ['title' => 'Service AC', 'every_days' => 30, 'next_due' => now()->toDateString()])->assertCreated()->json();
        $this->patchJson("/api/v1/maintenance/schedules/{$s['id']}", ['every_days' => 60, 'active' => false])->assertOk()
            ->assertJsonPath('every_days', 60)->assertJsonPath('active', false);
        $this->assertFalse(PmSchedule::find($s['id'])->active);
        $this->assertSame(0, $this->postJson('/api/v1/maintenance/schedules/run')->json('created'));
    }
}

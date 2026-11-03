<?php

namespace Tests\Feature\Edge;

use App\Modules\Guests\Domain\Guest;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\POS\Domain\Payment;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EdgeSyncTest extends EdgeTestCase
{
    private Reservation $reservation;

    private Room $room;

    private Outlet $bar;

    private MenuItem $beer;

    protected function setUp(): void
    {
        parent::setUp();
        [$type, $plan, $guest] = $this->seedInventory(rooms: 3, rate: 59_625);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM']);
        $this->reservation = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $this->room = Room::where('room_type_id', $type->id)->orderBy('id')->first();
        $this->bar = Outlet::create(['code' => 'BAR', 'name' => 'Lobby Bar', 'kind' => 'bar']);
        $this->beer = MenuItem::create(['code' => 'B33', 'name' => '33 Export', 'price' => 2_385, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $this->bootEdge();
    }

    private function offlineWork(): void
    {
        $this->onEdge(function () {
            $this->postJson("/api/v1/reservations/{$this->reservation->id}/check-in", ['room_id' => $this->room->id])
                ->assertOk()->assertJsonPath('status', 'checked_in');
            $this->postJson('/api/v1/cashier-shifts')->assertCreated();
            $this->postJson('/api/v1/pos/sync', ['checks' => [[
                'client_uuid' => '7a1f4c2e-9b3d-4e5f-8a6b-1c2d3e4f5a6b', 'outlet_id' => $this->bar->id,
                'items' => [['menu_item_id' => $this->beer->id, 'quantity' => 2]],
                'payments' => [['method' => 'CASH', 'amount' => 4_770]],
            ]]])->assertOk()->assertJsonPath('results.0.status', 'synced');
        });
    }

    public function test_offline_check_in_and_pos_sale_sync_to_cloud_exactly_once(): void
    {
        $this->offlineWork();
        $offset = $this->node->id * config('edge.id_block');
        $edgeCheck = $this->edgeTable('pos_checks')->first();
        $this->assertGreaterThanOrEqual($offset, $edgeCheck->id, 'edge ids live in the node id block');
        $this->assertStringEndsWith('-500001', $edgeCheck->number, 'edge document numbers use the reserved range');
        $this->assertSame(0, DB::table('pos_checks')->count(), 'nothing on the cloud before sync');

        $first = $this->sync();
        $this->assertGreaterThan(0, $first['push']['applied']);
        $this->assertSame(0, $first['push']['conflicts'], json_encode(DB::table('edge_conflicts')->get()));

        $this->assertSame('checked_in', DB::table('reservations')->where('id', $this->reservation->id)->value('status'));
        $this->assertTrue((bool) DB::table('rooms')->where('id', $this->room->id)->value('occupied'));
        $this->assertSame(1, DB::table('guest_register_entries')->where('reservation_id', $this->reservation->id)->count());
        $this->assertSame(1, DB::table('pos_checks')->count());
        $this->assertSame($edgeCheck->number, DB::table('pos_checks')->value('number'));
        $this->assertSame(1, DB::table('pos_payments')->count());
        $this->assertSame(
            $this->edgeTable('journal_lines')->count(), DB::table('journal_lines')->count(), 'every GL line arrived once');
        $this->assertBooksBalance();

        // Second run and a replayed batch change nothing.
        $counts = fn () => [DB::table('pos_checks')->count(), DB::table('pos_payments')->count(), DB::table('journal_lines')->count(), DB::table('folio_lines')->count()];
        $before = $counts();
        $again = $this->sync();
        $this->assertSame(0, $again['push']['batches']);
        $log = $this->onEdge(fn () => DB::table('edge_changes')->orderBy('id')->limit(5)->get());
        $replay = $this->cloudCall('push', [
            'batch_key' => 'manual-replay', 'from_cursor' => 0, 'to_cursor' => (int) $log->last()->id,
            'changes' => $this->onEdge(fn () => app(\App\Modules\Edge\Application\ChangeFeed::class)->build($log)),
        ]);
        $this->assertSame(0, $replay['applied']);
        $this->assertSame($replay['skipped'], count($replay) ? $replay['skipped'] : 0);
        $this->assertSame($before, $counts());
        $this->assertSame(0, DB::table('edge_conflicts')->count());
    }

    public function test_cloud_wins_for_master_data_and_rates(): void
    {
        $this->onEdge(fn () => MenuItem::whereKey($this->beer->id)->update(['price' => 1]) && MenuItem::find($this->beer->id)->update(['price' => 999]));
        $this->onCloud(fn () => $this->assertSame(2_385, (int) DB::table('pos_menu_items')->where('id', $this->beer->id)->value('price')));

        $this->sync();
        $this->assertSame(2_385, (int) DB::table('pos_menu_items')->where('id', $this->beer->id)->value('price'));
        $this->assertDatabaseHas('edge_conflicts', ['table_name' => 'pos_menu_items', 'row_id' => $this->beer->id, 'rule' => 'master_cloud_wins']);
        $this->assertSame(2_385, (int) $this->edgeTable('pos_menu_items')->where('id', $this->beer->id)->value('price'), 'edge reverted on pull');

        // A cloud rate change reaches the edge.
        MenuItem::find($this->beer->id)->update(['price' => 2_500]);
        $this->sync();
        $this->assertSame(2_500, (int) $this->edgeTable('pos_menu_items')->where('id', $this->beer->id)->value('price'));
    }

    public function test_last_writer_wins_for_room_housekeeping_status(): void
    {
        $rooms = Room::orderBy('id')->get();
        // Room A: edge writes last -> edge wins. Room B: cloud writes last -> cloud wins.
        Carbon::setTestNow(now()->setTime(10, 0));
        $this->onEdge(fn () => Room::find($rooms[1]->id)->update(['hk_status' => 'inspected']));
        Room::find($rooms[0]->id)->update(['hk_status' => 'vacant_dirty']);
        Carbon::setTestNow(now()->setTime(11, 0));
        $this->onEdge(fn () => Room::find($rooms[0]->id)->update(['hk_status' => 'inspected']));
        Room::find($rooms[1]->id)->update(['hk_status' => 'out_of_order']);
        Carbon::setTestNow();

        $this->sync();
        $this->assertSame('inspected', DB::table('rooms')->where('id', $rooms[0]->id)->value('hk_status'));
        $this->assertSame('out_of_order', DB::table('rooms')->where('id', $rooms[1]->id)->value('hk_status'));
        $this->assertSame('inspected', $this->edgeTable('rooms')->where('id', $rooms[0]->id)->value('hk_status'));
        $this->assertSame('out_of_order', $this->edgeTable('rooms')->where('id', $rooms[1]->id)->value('hk_status'));
        $this->assertDatabaseHas('edge_conflicts', ['table_name' => 'rooms', 'row_id' => $rooms[1]->id, 'rule' => 'lww_kept_newer']);
    }

    public function test_financial_events_are_appended_never_overwritten(): void
    {
        $this->offlineWork();
        $this->sync();
        $payment = $this->edgeTable('pos_payments')->first();

        $this->onEdge(function () use ($payment) {
            Payment::find($payment->id)->update(['amount' => 1]);
            Payment::find($payment->id)->delete();
        });
        $this->sync();
        $this->assertSame(4_770, (int) DB::table('pos_payments')->where('id', $payment->id)->value('amount'));
        $this->assertDatabaseHas('edge_conflicts', ['table_name' => 'pos_payments', 'rule' => 'financial_append_only']);
        $this->assertBooksBalance();
    }

    public function test_cursor_resumes_after_interruption(): void
    {
        config(['edge.batch_size' => 2]);
        $this->onEdge(function () {
            foreach (range(1, 5) as $i) {
                Guest::create(['first_name' => "Edge$i", 'last_name' => 'Walkin']);
            }
        });

        // Push: batch 1 lands, batch 2 is lost on the wire before reaching the cloud.
        $this->fault = fn ($endpoint, $call) => $endpoint === 'push' && $call === 2 ? 'before' : null;
        try {
            $this->sync();
            $this->fail('sync should fail');
        } catch (RuntimeException) {
        }
        $this->assertSame(2, Guest::where('last_name', 'Walkin')->count());
        $this->assertNotNull($this->onEdge(fn () => \App\Modules\Edge\Application\EdgeSyncer::get('last_error')));

        // Batch 3 is applied but its response is lost: the edge retries, the cloud replays.
        $this->calls = 0;
        $this->fault = fn ($endpoint, $call) => $endpoint === 'push' && $call === 2 ? 'after' : null;
        try {
            $this->sync();
        } catch (RuntimeException) {
        }
        $this->fault = null;
        $this->sync();

        $this->assertSame(5, Guest::where('last_name', 'Walkin')->count(), 'each guest exactly once');
        $edgeMax = $this->edgeTable('edge_changes')->whereNull('origin_node_id')->max('id');
        $this->assertSame((int) $edgeMax, (int) DB::table('edge_nodes')->where('id', $this->node->id)->value('push_cursor'));
        $this->assertSame(0, DB::table('edge_conflicts')->count());

        // Pull: interrupted after the first page, resumes from the stored cursor.
        foreach (range(1, 5) as $i) {
            Guest::create(['first_name' => "Cloud$i", 'last_name' => 'Online']);
        }
        $this->calls = 0;
        $this->fault = fn ($endpoint, $call) => $endpoint === 'pull' && $call === 2 ? 'before' : null;
        try {
            $this->sync();
        } catch (RuntimeException) {
        }
        $this->assertSame(2, $this->edgeTable('guests')->where('last_name', 'Online')->count());
        $this->fault = null;
        $this->sync();
        $this->assertSame(5, $this->edgeTable('guests')->where('last_name', 'Online')->count());
        $this->assertSame(5, $this->edgeTable('guests')->where('last_name', 'Walkin')->count());
    }

    public function test_tenant_isolation(): void
    {
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $otherRoom = $this->onCloud(function () use ($other) {
            $ctx = app(\App\Platform\Tenancy\TenantContext::class);
            $ctx->forHotel($other);
            $type = \App\Modules\Property\Domain\RoomType::create(['code' => 'STD', 'name' => 'Std', 'max_adults' => 2, 'max_children' => 0, 'base_rate' => 1]);
            $room = Room::create(['room_type_id' => $type->id, 'number' => '900']);
            $room->update(['hk_status' => 'inspected']);
            $this->actAs($this->user, $this->hotel);

            return $room;
        });

        // Another hotel's changes never reach this edge.
        $this->sync();
        $this->assertSame(0, $this->edgeTable('rooms')->where('id', $otherRoom->id)->count());
        $pull = $this->cloudCall('pull', ['cursor' => 0]);
        $this->assertEmpty(collect($pull['changes'])->filter(fn ($c) => $c['hotel_id'] === $other->id));

        // A forged push for another hotel is rejected.
        $forged = (array) DB::table('rooms')->where('id', $otherRoom->id)->first();
        $forged['hk_status'] = 'out_of_order';
        $res = $this->cloudCall('push', ['batch_key' => 'forged', 'from_cursor' => 10**15, 'to_cursor' => 10**15 + 1, 'changes' => [[
            'seq' => 10**15 + 1, 'table' => 'rooms', 'row_id' => $otherRoom->id, 'op' => 'upsert', 'changed_at' => now()->addDay()->toIso8601String(),
            'hotel_id' => $this->hotel->id, 'group_id' => $this->group->id, 'data' => ['hotel_id' => $this->hotel->id] + $forged,
        ], [
            'seq' => 10**15 + 1, 'table' => 'hotels', 'row_id' => $other->id, 'op' => 'upsert', 'changed_at' => now()->toIso8601String(),
            'hotel_id' => $other->id, 'group_id' => $this->group->id, 'data' => ['id' => $other->id, 'name' => 'Hijacked'],
        ]]]);
        $this->assertSame(2, $res['conflicts']);
        $this->assertSame('inspected', DB::table('rooms')->where('id', $otherRoom->id)->value('hk_status'));
        $this->assertNotSame('Hijacked', DB::table('hotels')->where('id', $other->id)->value('name'));
        $this->assertSame(2, DB::table('edge_conflicts')->where('rule', 'tenant_rejected')->count());

        // Signed tokens: bad signature, stale timestamp, revoked node.
        $this->signedPost('pull', ['cursor' => 0], ['X-Edge-Node' => $this->node->id, 'X-Edge-Timestamp' => time(), 'X-Edge-Signature' => 'bad'])->assertStatus(401);
        $stale = \App\Modules\Edge\Application\Signature::headers($this->node->id, $this->secret, 'POST', 'api/v1/edge/sync/pull', json_encode(['cursor' => 0]), time() - 3600);
        $this->signedPost('pull', ['cursor' => 0], $stale)->assertStatus(401);
        $this->postJson("/api/v1/edge/nodes/{$this->node->id}/revoke")->assertOk();
        $this->signedPost('pull', ['cursor' => 0])->assertStatus(401);
    }

    public function test_status_screen_api_requires_edge_permission(): void
    {
        $this->getJson('/api/v1/edge/status')->assertOk()->assertJsonPath('mode', 'cloud')->assertJsonPath('nodes.0.id', $this->node->id);
        $this->postJson('/api/v1/edge/nodes', ['name' => 'Second'])->assertStatus(409);

        $this->offlineWork();
        $this->onEdge(fn () => $this->getJson('/api/v1/edge/status')->assertOk()->assertJsonPath('mode', 'edge')
            ->assertJsonPath('edge.pending', fn ($n) => $n > 0));

        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel)->getJson('/api/v1/edge/status')->assertForbidden();
        $this->actingAs($receptionist, 'web')->get('/edge')->assertForbidden();

        [, $fresh] = $this->makeHotel('Akwa Test', 'KRI', $this->group);
        $this->actAs($this->user, $fresh)->postJson('/api/v1/edge/nodes', ['name' => 'Kribi edge'])->assertCreated()
            ->assertJsonStructure(['id', 'secret']);
    }
}

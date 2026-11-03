<?php

namespace Tests\Feature\Reports;

use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    private function book(array $inv, int $startIn, int $nights, ?int $roomId = null, string $channel = 'direct')
    {
        [$type, $plan, $guest] = $inv;

        return app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'channel' => $channel,
            'arrival' => now()->addDays($startIn)->toDateString(), 'departure' => now()->addDays($startIn + $nights)->toDateString(),
            'room_id' => $roomId,
        ]);
    }

    public function test_reports_require_permission(): void
    {
        $this->actAs($this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]), $this->hotel);
        foreach (['dashboard', 'forecast', 'group-dashboard', 'channels?from=2026-01-01&to=2026-01-02'] as $r) {
            $this->getJson("/api/v1/reports/$r")->assertForbidden();
        }
    }

    public function test_dashboard_counts_out_of_order_arrivals_and_dirty(): void
    {
        $inv = $this->seedInventory(rooms: 4, rate: 40_000);
        Room::where('number', '101')->update(['hk_status' => 'out_of_order']);
        Room::where('number', '102')->update(['hk_status' => 'vacant_dirty']);
        $this->book($inv, 0, 2);

        $t = $this->getJson('/api/v1/reports/dashboard')->assertOk()->json('today');
        $this->assertSame(4, $t['rooms_total']);
        $this->assertSame(1, $t['out_of_order']);
        $this->assertSame(3, $t['rooms_available']);
        $this->assertSame(0, $t['rooms_sold']);
        $this->assertSame(1, $t['arrivals']);
        $this->assertSame(1, $t['dirty_rooms']);
        $this->assertEquals(0, $t['occupancy_percent']);
    }

    public function test_forecast_shows_rooms_on_the_books_and_caps_days(): void
    {
        $inv = $this->seedInventory(rooms: 2, rate: 50_000);
        $this->book($inv, 1, 2);

        $f = $this->getJson('/api/v1/reports/forecast?days=5')->assertOk()->json();
        $this->assertCount(5, $f);
        $this->assertSame(0, $f[0]['rooms_sold']);
        $this->assertSame(1, $f[1]['rooms_sold']);
        $this->assertEquals(50.0, $f[1]['occupancy_percent']);
        $this->assertSame(50_000, $f[1]['room_revenue']);
        $this->assertSame(50_000, $f[2]['adr']);
        $this->assertSame(0, $f[3]['rooms_sold']);
        $this->assertCount(365, $this->getJson('/api/v1/reports/forecast?days=9999')->assertOk()->json());
    }

    public function test_channels_groups_production_and_excludes_cancelled(): void
    {
        $inv = $this->seedInventory(rooms: 3, rate: 30_000);
        $this->book($inv, 1, 2, channel: 'website');
        $this->book($inv, 1, 1, channel: 'direct');
        $cancelled = $this->book($inv, 1, 3, channel: 'direct');
        app(ReservationService::class)->cancel($cancelled, 'test');

        $this->getJson('/api/v1/reports/channels')->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $rows = collect($this->getJson('/api/v1/reports/channels?from='.now()->toDateString().'&to='.now()->addDays(10)->toDateString())->assertOk()->json())->keyBy('channel');
        $this->assertSame(2, (int) $rows['website']['room_nights']);
        $this->assertSame(60_000, (int) $rows['website']['revenue']);
        $this->assertSame(1, (int) $rows['direct']['room_nights']);
        $this->assertSame(1, (int) $rows['direct']['bookings']);
    }

    public function test_group_dashboard_sums_hotels_and_respects_access(): void
    {
        $inv = $this->seedInventory(rooms: 2, rate: 45_000);
        $inv[2]->update(['id_number' => 'X']);
        $r = $this->book($inv, 0, 1, Room::first()->id);
        app(FrontDeskService::class)->checkIn($r);
        [, $second] = $this->makeHotel('Akwa Test', 'BON', $this->group);

        $g = $this->getJson('/api/v1/reports/group-dashboard')->assertOk()->json();
        $this->assertCount(2, $g['hotels']);
        $this->assertSame(2, $g['total']['rooms_available']);
        $this->assertSame(1, $g['total']['rooms_sold']);
        $this->assertEquals(50.0, $g['total']['occupancy_percent']);

        $this->actAs($this->makeUser($this->group, 'general-manager', hotels: [$second]), $second);
        $only = $this->getJson('/api/v1/reports/group-dashboard')->assertOk()->json();
        $this->assertSame(['BON'], array_column(array_column($only['hotels'], 'hotel'), 'code'));
    }
}

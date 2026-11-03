<?php

namespace Tests\Feature\Reservations;

use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Shared fixtures for the R-A revenue engine tests: STD (50k) and DLX (80k) room types on the BAR plan. */
abstract class RevenueBaseTest extends TestCase
{
    protected RoomType $std;

    protected RoomType $dlx;

    protected RatePlan $bar;

    protected $guest;

    protected function setUpRevenue(int $stdRooms = 2, int $dlxRooms = 2): void
    {
        [$this->dlx, $this->bar, $this->guest] = $this->seedInventory(rooms: $dlxRooms, rate: 80_000);
        $this->dlx->update(['sort' => 2]);
        $this->std = RoomType::create(['code' => 'STD', 'name' => 'Standard', 'max_adults' => 2, 'max_children' => 1, 'base_rate' => 50_000, 'sort' => 1]);
        foreach (range(1, $stdRooms) as $i) {
            Room::create(['room_type_id' => $this->std->id, 'number' => (string) (200 + $i)]);
        }
    }

    protected function book(RoomType $type, int $startIn, int $nights, array $extra = []): Reservation
    {
        return app(ReservationService::class)->create($extra + [
            'guest_id' => $this->guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $this->bar->id,
            'arrival' => Carbon::today()->addDays($startIn)->toDateString(), 'departure' => Carbon::today()->addDays($startIn + $nights)->toDateString(),
        ]);
    }

    protected function day(int $offset): string
    {
        return Carbon::today()->addDays($offset)->toDateString();
    }

    protected function asRole(string $role): void
    {
        $this->actAs($this->makeUser($this->group, $role, allHotels: true), $this->hotel);
    }

    protected function anonymous(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Hotel-Id' => null]);
    }
}

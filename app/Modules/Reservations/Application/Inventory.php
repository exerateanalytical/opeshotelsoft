<?php

namespace App\Modules\Reservations\Application;

use App\Modules\Property\Domain\Room;
use App\Platform\Domain\DomainException;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Room-type inventory per night.
 *   available = physical sellable rooms - out_of_order + overbooking_limit - sold - blocked
 * take()/release() lock the inventory rows (SELECT ... FOR UPDATE) so two
 * concurrent bookings for the last room cannot both succeed.
 */
class Inventory
{
    public function capacity(int $roomTypeId): int
    {
        return Room::where('room_type_id', $roomTypeId)->where('active', true)
            ->whereNotIn('hk_status', ['out_of_service'])->count();
    }

    /** @return array<string, array{date:string, capacity:int, sold:int, blocked:int, out_of_order:int, available:int, stop_sell:bool}> */
    public function grid(int $hotelId, int $roomTypeId, string $from, string $to): array
    {
        $capacity = $this->capacity($roomTypeId);
        $rows = DB::table('room_type_inventory')->where('room_type_id', $roomTypeId)
            ->whereBetween('date', [$from, $to])->get()->keyBy(fn ($r) => substr($r->date, 0, 10));

        $out = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $d = $day->toDateString();
            $r = $rows[$d] ?? null;
            $sold = $r->sold ?? 0;
            $blocked = $r->blocked ?? 0;
            $ooo = $r->out_of_order ?? 0;
            $over = $r->overbooking_limit ?? 0;
            $out[$d] = [
                'date' => $d,
                'capacity' => $capacity,
                'sold' => $sold,
                'blocked' => $blocked,
                'out_of_order' => $ooo,
                'available' => ($r->stop_sell ?? false) ? 0 : max(0, $capacity - $ooo + $over - $sold - $blocked),
                'stop_sell' => (bool) ($r->stop_sell ?? false),
            ];
        }

        return $out;
    }

    /** Take one room of a type for each night in [arrival, departure). */
    public function take(int $hotelId, int $roomTypeId, Carbon $arrival, Carbon $departure, bool $fromBlock = false): void
    {
        $capacity = $this->capacity($roomTypeId);
        foreach ($this->nights($arrival, $departure) as $d) {
            $row = $this->lockRow($hotelId, $roomTypeId, $d);
            if ($fromBlock) {
                DB::table('room_type_inventory')->where('id', $row->id)->update([
                    'blocked' => max(0, $row->blocked - 1), 'sold' => $row->sold + 1,
                ]);

                continue;
            }
            $available = $row->stop_sell ? 0 : $capacity - $row->out_of_order + $row->overbooking_limit - $row->sold - $row->blocked;
            if ($available < 1) {
                throw new DomainException("No rooms of this type are available on $d.", 'not-available', 409);
            }
            DB::table('room_type_inventory')->where('id', $row->id)->increment('sold');
        }
    }

    public function release(int $hotelId, int $roomTypeId, Carbon $arrival, Carbon $departure): void
    {
        foreach ($this->nights($arrival, $departure) as $d) {
            $row = $this->lockRow($hotelId, $roomTypeId, $d);
            DB::table('room_type_inventory')->where('id', $row->id)->update(['sold' => max(0, $row->sold - 1)]);
        }
    }

    public function adjust(int $hotelId, int $roomTypeId, string $date, string $column, int $delta): void
    {
        $row = $this->lockRow($hotelId, $roomTypeId, $date);
        DB::table('room_type_inventory')->where('id', $row->id)->update([$column => max(0, $row->$column + $delta)]);
    }

    public function set(int $hotelId, int $roomTypeId, string $date, array $values): void
    {
        $row = $this->lockRow($hotelId, $roomTypeId, $date);
        DB::table('room_type_inventory')->where('id', $row->id)->update($values);
    }

    /** @return string[] */
    public function nights(Carbon $arrival, Carbon $departure): array
    {
        $out = [];
        for ($d = $arrival->copy(); $d->lt($departure); $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    private function lockRow(int $hotelId, int $roomTypeId, string $date): object
    {
        DB::table('room_type_inventory')->insertOrIgnore(['hotel_id' => $hotelId, 'room_type_id' => $roomTypeId, 'date' => $date]);

        return DB::table('room_type_inventory')->where('room_type_id', $roomTypeId)->where('date', $date)->lockForUpdate()->first();
    }
}

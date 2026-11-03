<?php

namespace App\Modules\Reservations\Experience\Http;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\TapeOps;
use Illuminate\Http\Request;

/** Drag-and-drop tape chart operations. dry_run=true answers "can I drop here?" and changes nothing. */
class TapeController
{
    public function __construct(private TapeOps $ops) {}

    public function move(Request $request)
    {
        $d = $request->validate(['reservation_id' => ['required', 'integer'], 'room_id' => ['required', 'integer'], 'dry_run' => ['sometimes', 'boolean']]);
        $r = Reservation::findOrFail($d['reservation_id']);

        return $this->respond($d, fn () => $this->ops->move($r, $d['room_id']), fn ($res) => $this->one($res));
    }

    public function resize(Request $request)
    {
        $d = $request->validate(['reservation_id' => ['required', 'integer'], 'arrival' => ['nullable', 'date'], 'departure' => ['nullable', 'date'], 'dry_run' => ['sometimes', 'boolean']]);
        $r = Reservation::findOrFail($d['reservation_id']);
        $old = (int) $r->total_amount;

        return $this->respond($d, fn () => $this->ops->resize($r, $d['arrival'] ?? null, $d['departure'] ?? null), fn ($res) => $this->one($res) + ['old_total' => $old, 'difference' => (int) $res->total_amount - $old]);
    }

    public function split(Request $request)
    {
        $d = $request->validate(['reservation_id' => ['required', 'integer'], 'split_date' => ['required', 'date'], 'room_id' => ['required', 'integer'], 'dry_run' => ['sometimes', 'boolean']]);
        $r = Reservation::findOrFail($d['reservation_id']);

        return $this->respond($d, fn () => $this->ops->split($r, $d['split_date'], $d['room_id']), fn ($pair) => ['first' => $this->one($pair[0]), 'second' => $this->one($pair[1])]);
    }

    public function swap(Request $request)
    {
        $d = $request->validate(['reservation_id' => ['required', 'integer'], 'with_reservation_id' => ['required', 'integer'], 'dry_run' => ['sometimes', 'boolean']]);
        $a = Reservation::findOrFail($d['reservation_id']);
        $b = Reservation::findOrFail($d['with_reservation_id']);

        return $this->respond($d, fn () => $this->ops->swap($a, $b), fn ($pair) => ['a' => $this->one($pair[0]), 'b' => $this->one($pair[1])]);
    }

    private function respond(array $d, callable $op, callable $present)
    {
        if ($d['dry_run'] ?? false) {
            return $this->ops->check($op);
        }

        return ['ok' => true] + $present($op());
    }

    private function one(Reservation $r): array
    {
        return ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'room_id' => $r->room_id, 'room_type_id' => $r->room_type_id,
            'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(), 'total_amount' => (int) $r->total_amount];
    }
}

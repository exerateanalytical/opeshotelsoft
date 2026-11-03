<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Models\User;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Domain\RoomSuggestion;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Smart room assignment: ranks the free rooms of the booked type for each unassigned arrival and stores the best three as
 * suggestions that staff accept or reject. Nothing is assigned until a person accepts.
 * Scoring: housekeeping state (ready beats dirty beats occupied), the guest's preferences (floor, view, quiet),
 * adjacency for groups and families (same booking reference or "together" preference), and loyalty tier: higher tiers are
 * served first and get a bonus, so they pick the best rooms before others.
 */
class RoomSuggester
{
    public function __construct(private TapeOps $tape, private ReservationService $reservations) {}

    /** @return Collection<int, RoomSuggestion> */
    public function suggestForDate(string $date): Collection
    {
        $arrivals = Reservation::with('guest:id,first_name,last_name,vip')->whereIn('status', ['tentative', 'confirmed'])
            ->whereDate('arrival', $date)->whereNull('room_id')->get();
        $rank = $this->tierRanks($arrivals->pluck('guest_id')->all());
        $ordered = $arrivals->sortBy([
            fn ($a, $b) => ($rank[$b->guest_id] ?? 0) <=> ($rank[$a->guest_id] ?? 0),
            fn ($a, $b) => (int) ($b->guest?->vip) <=> (int) ($a->guest?->vip),
            fn ($a, $b) => $a->id <=> $b->id,
        ]);

        RoomSuggestion::whereIn('reservation_id', $arrivals->pluck('id'))->where('status', 'pending')->delete();
        $taken = [];
        $out = collect();
        foreach ($ordered as $r) {
            foreach ($this->rank($r, $taken, $rank[$r->guest_id] ?? 0) as $i => $c) {
                $out->push(RoomSuggestion::updateOrCreate(['reservation_id' => $r->id, 'room_id' => $c['room']->id], ['score' => $c['score'], 'reasons' => $c['reasons'], 'status' => 'pending']));
                if ($i === 0) {
                    $taken[$c['room']->id] = $c['room'];
                }
            }
        }

        return $out;
    }

    /** @return array<int, array{room:Room, score:int, reasons:string[]}> top three */
    private function rank(Reservation $r, array $taken, int $tier): array
    {
        $rejected = RoomSuggestion::where('reservation_id', $r->id)->where('status', 'rejected')->pluck('room_id')->all();
        $prefs = (array) ($r->room_prefs ?? []);
        $siblings = $this->siblingRooms($r);
        $c = [];
        foreach (Room::where('active', true)->where('room_type_id', $r->room_type_id)->get() as $room) {
            if (isset($taken[$room->id]) || in_array($room->id, $rejected, true) || $this->tape->conflicts($room, $r->arrival, $r->departure, [$r->id])) {
                continue;
            }
            [$score, $why] = [50, []];
            if ($room->isReadyForArrival()) {
                [$score, $why[]] = [$score + 20, 'ready'];
            } elseif ($room->hk_status === 'vacant_dirty') {
                [$score, $why[]] = [$score + 8, 'dirty'];
            } elseif ($room->occupied) {
                [$score, $why[]] = [$score - 15, 'occupied'];
            }
            if (! empty($prefs['floor']) && (string) $prefs['floor'] === (string) $room->floor) {
                [$score, $why[]] = [$score + 25, 'floor'];
            }
            if (! empty($prefs['view']) && strcasecmp((string) $prefs['view'], (string) $room->view) === 0) {
                [$score, $why[]] = [$score + 15, 'view'];
            }
            if (! empty($prefs['quiet']) && $room->quiet) {
                [$score, $why[]] = [$score + 15, 'quiet'];
            }
            if ($tier > 0) {
                $best = ($room->view ? 4 : 0) + ($room->quiet ? 3 : 0) + (is_numeric($room->floor) ? min(5, (int) $room->floor) : 0);
                [$score, $why[]] = [$score + $tier * 3 + $best, 'tier'];
            }
            foreach ($siblings as $s) {
                if ($s->floor !== null && (string) $s->floor === (string) $room->floor) {
                    $adjacent = is_numeric($s->number) && is_numeric($room->number) && abs((int) $s->number - (int) $room->number) === 1;
                    [$score, $why[]] = [$score + ($adjacent ? 40 : 20), $adjacent ? 'adjacent' : 'same_floor'];
                    break;
                }
            }
            $c[] = ['room' => $room, 'score' => $score, 'reasons' => array_values(array_unique($why))];
        }
        usort($c, fn ($a, $b) => [$b['score'], $a['room']->number] <=> [$a['score'], $b['room']->number]);

        return array_slice($c, 0, 3);
    }

    /** Rooms of the other stays in the same booking (or the booking named in "together"): assigned, or the best pending suggestion. */
    private function siblingRooms(Reservation $r): Collection
    {
        $ref = $r->room_prefs['together'] ?? $r->booking_ref;
        $sibs = Reservation::where('booking_ref', $ref)->where('id', '!=', $r->id)->whereIn('status', Reservation::ACTIVE)->get(['id', 'room_id']);
        $ids = $sibs->pluck('room_id')->filter()->all();
        foreach ($sibs->whereNull('room_id') as $s) {
            $best = RoomSuggestion::where('reservation_id', $s->id)->where('status', 'pending')->orderByDesc('score')->value('room_id');
            if ($best) {
                $ids[] = $best;
            }
        }

        return $ids ? Room::whereIn('id', $ids)->get() : collect();
    }

    private function tierRanks(array $guestIds): array
    {
        if (! $guestIds) {
            return [];
        }

        return DB::table('loyalty_memberships as m')->join('loyalty_tiers as t', 't.id', '=', 'm.loyalty_tier_id')
            ->whereIn('m.guest_id', $guestIds)->where('m.status', 'active')->pluck('t.rank', 'm.guest_id')->map(fn ($v) => (int) $v)->all();
    }

    public function accept(RoomSuggestion $s, ?User $by = null): Reservation
    {
        return DB::transaction(function () use ($s, $by) {
            $s = RoomSuggestion::lockForUpdate()->findOrFail($s->id);
            if ($s->status !== 'pending') {
                throw new DomainException("This suggestion was already {$s->status}.", 'suggestion-closed', 409);
            }
            $r = Reservation::findOrFail($s->reservation_id);
            if ($r->room_id) {
                throw new DomainException("Reservation {$r->number} already has a room.", 'room-assigned', 409);
            }
            $room = Room::findOrFail($s->room_id);
            if ($bad = $this->tape->conflicts($room, $r->arrival, $r->departure, [$r->id])) {
                throw new DomainException(implode(' ', $bad), 'room-conflict', 409);
            }
            $this->reservations->assignRoom($r, $room->id);
            $s->update(['status' => 'accepted', 'decided_by' => $by?->id, 'decided_at' => now()]);
            RoomSuggestion::where('reservation_id', $r->id)->where('status', 'pending')->where('id', '!=', $s->id)->delete();

            return $r->refresh();
        });
    }

    public function reject(RoomSuggestion $s, ?User $by = null): RoomSuggestion
    {
        if ($s->status !== 'pending') {
            throw new DomainException("This suggestion was already {$s->status}.", 'suggestion-closed', 409);
        }
        $s->update(['status' => 'rejected', 'decided_by' => $by?->id, 'decided_at' => now()]);

        return $s;
    }
}

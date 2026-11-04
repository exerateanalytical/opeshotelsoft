<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\CashierShift;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\ReservationNight;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day: post room & tourist tax for every in-house guest, process
 * no-shows, compute statistics, close the business day and roll the date.
 * Idempotent per night: a night already posted is never posted twice.
 */
class NightAudit
{
    public function __construct(
        private FolioService $folios,
        private ReservationService $reservations,
        private NoShowFee $noShowFee,
        private EventBus $bus,
        private TenantContext $ctx,
    ) {}

    /** Checks to show the auditor before running. */
    public function preCheck(Hotel $hotel): array
    {
        $date = $hotel->business_date->toDateString();

        return [
            'business_date' => $date,
            'arrivals_not_checked_in' => Reservation::where('status', 'confirmed')->whereDate('arrival', $date)->count(),
            'departures_not_checked_out' => Reservation::where('status', 'checked_in')->whereDate('departure', '<=', $date)->count(),
            'open_cashier_shifts' => CashierShift::where('status', 'open')->count(),
            'in_house' => Reservation::where('status', 'checked_in')->count(),
        ];
    }

    public function run(Hotel $hotel, bool $markNoShows = true): array
    {
        return DB::transaction(function () use ($hotel, $markNoShows) {
            $hotel = Hotel::lockForUpdate()->findOrFail($hotel->id);
            $date = $hotel->business_date->toDateString();

            $closed = DB::table('business_days')->where('hotel_id', $hotel->id)->where('date', $date)->where('status', 'closed')->exists();
            if ($closed) {
                throw new DomainException("Business day $date is already closed.");
            }
            $late = Reservation::where('status', 'checked_in')->whereDate('departure', '<', $date)->count();
            if ($late > 0) {
                throw new DomainException("$late guest(s) are past their departure date. Check them out or extend before the audit.", 'audit-blocked');
            }

            $posted = 0;
            $roomRevenue = 0;
            $inHouse = Reservation::where('status', 'checked_in')->where('arrival', '<=', $date)->where('departure', '>', $date)->with('guest', 'room')->get();
            foreach ($inHouse as $r) {
                $night = ReservationNight::where('reservation_id', $r->id)->whereDate('date', $date)->lockForUpdate()->first();
                if (! $night || $night->posted) {
                    continue;
                }
                $folio = $this->folios->guestFolioFor($r);
                $this->folios->charge($folio, 'ROOM', $night->amount, [
                    'description' => "Room {$r->room?->number} - $date", 'source_type' => 'reservation_night',
                    'source_id' => $night->id, 'business_date' => $date, 'nights' => 1,
                ]);
                $night->update(['posted' => true]);
                $posted++;
                $roomRevenue += $night->amount;
            }

            $noShows = [];
            if ($markNoShows) {
                foreach (Reservation::whereIn('status', ['confirmed', 'tentative'])->whereDate('arrival', $date)->get() as $r) {
                    $this->reservations->markNoShow($r);
                    $this->noShowFee->post($r);
                    $noShows[] = $r->number;
                }
            }

            $rooms = Room::where('active', true)->count();
            $ooo = Room::where('hk_status', 'out_of_order')->count();
            $occupied = $inHouse->count();
            $available = max(1, $rooms - $ooo);
            $revenue = (int) FolioLine::where('hotel_id', $hotel->id)->whereDate('business_date', $date)
                ->whereIn('type', ['charge', 'reversal'])->selectRaw('department, sum(amount) as total')
                ->groupBy('department')->pluck('total', 'department')->map(fn ($v) => (int) $v)->sum();

            $summary = [
                'date' => $date,
                'rooms' => $rooms,
                'out_of_order' => $ooo,
                'occupied' => $occupied,
                'occupancy_percent' => round($occupied / $available * 100, 2),
                'room_revenue' => $roomRevenue,
                'adr' => $occupied ? intdiv($roomRevenue, $occupied) : 0,
                'revpar' => intdiv($roomRevenue, $available),
                'total_revenue' => $revenue,
                'room_nights_posted' => $posted,
                'no_shows' => $noShows,
                'revenue_by_department' => FolioLine::where('hotel_id', $hotel->id)->whereDate('business_date', $date)
                    ->whereIn('type', ['charge', 'reversal'])->selectRaw('department, sum(amount) as total')
                    ->groupBy('department')->pluck('total', 'department'),
            ];

            DB::table('business_days')->updateOrInsert(
                ['hotel_id' => $hotel->id, 'date' => $date],
                ['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->ctx->actor()?->id, 'summary' => json_encode($summary)],
            );
            $next = $hotel->business_date->copy()->addDay();
            $hotel->forceFill(['business_date' => $next])->save();
            DB::table('business_days')->insertOrIgnore(['hotel_id' => $hotel->id, 'date' => $next->toDateString(), 'status' => 'open']);

            $this->bus->record('frontoffice.business_day_closed', $summary, 'hotel', $hotel->id);

            return $summary + ['new_business_date' => $next->toDateString()];
        });
    }
}

<?php

namespace App\Modules\Loyalty;

use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Program;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;

class LoyaltyModule
{
    public static function subscribe(EventBus $bus): void
    {
        // Earn on check-out: all eligible charges on the stay's folios (rooms, F&B charged to room...).
        $bus->subscribe('frontoffice.guest_checked_out', 'loyalty.earn_stay', function (StoredEvent $e) {
            $program = Program::where('active', true)->first();
            $member = $program ? Membership::where('guest_id', $e->payload['guest_id'])->where('status', 'active')->first() : null;
            if (! $member) {
                return;
            }
            $r = Reservation::find($e->payload['id']);
            $folioIds = Folio::where('reservation_id', $r->id)->pluck('id');
            $spend = (int) FolioLine::whereIn('folio_id', $folioIds)->whereIn('type', ['charge', 'reversal'])
                ->whereIn('department', $program->eligible_departments)->selectRaw('coalesce(sum(amount - tax_amount),0) as s')->value('s');
            app(LoyaltyService::class)->earn($member, $spend, $r->nightCount(), 'reservation', $r->id, "Séjour {$r->number}");
        });

        // Earn on direct outlet spend when a member is identified on the check.
        $bus->subscribe('pos.check_settled', 'loyalty.earn_pos', function (StoredEvent $e) {
            $p = $e->payload;
            if ($p['settlement'] !== 'payment' || empty($p['guest_id'])) {
                return;
            }
            $member = Membership::where('guest_id', $p['guest_id'])->where('status', 'active')->first();
            if ($member) {
                $net = (int) collect($p['revenue'])->sum('net');
                app(LoyaltyService::class)->earn($member, $net, 0, 'pos_check', $p['id'], "Point de vente {$p['outlet']} #{$p['number']}");
            }
        });
    }
}

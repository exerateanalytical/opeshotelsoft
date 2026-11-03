<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Modules\Reservations\Revenue\Domain\RevenueSetting;

/** Per-hotel revenue settings with sane defaults (created on first read). */
class RevenueConfig
{
    public const DEFAULTS = [
        'weekend_days' => [5, 6],
        'upsell_discount_pct' => ['booking' => 0, 'pre_arrival' => 10, 'check_in' => 15],
    ];

    public function get(): RevenueSetting
    {
        $s = RevenueSetting::first() ?? RevenueSetting::create(self::DEFAULTS);

        return $s->refresh();
    }
}

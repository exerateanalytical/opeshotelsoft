<?php

namespace App\Modules\Insights\Application;

use App\Modules\Insights\Domain\Setting;

/**
 * Catalogue of leakage rules with their default thresholds. Hotels override
 * any key (and switch rules off) in insight_settings.rules.
 * "source" says how the rule is evaluated: event subscriber or insights:scan.
 */
final class Rules
{
    public const ALL = [
        'void_after_fire' => ['severity' => 'high', 'source' => 'event', 'enabled' => true, 'min_amount' => 0],
        'comp_above' => ['severity' => 'medium', 'source' => 'event', 'enabled' => true, 'min_amount' => 10_000],
        'discount_above' => ['severity' => 'medium', 'source' => 'event', 'enabled' => true, 'percent' => 15, 'min_amount' => 5_000],
        'room_move_no_charge' => ['severity' => 'medium', 'source' => 'event', 'enabled' => true],
        'occupied_no_posting' => ['severity' => 'high', 'source' => 'scan', 'enabled' => true],
        'cashier_variance' => ['severity' => 'high', 'source' => 'event', 'enabled' => true, 'min_amount' => 2_000],
        'bar_stock_gap' => ['severity' => 'high', 'source' => 'scan', 'enabled' => true, 'percent' => 5, 'min_amount' => 5_000],
        'late_checkout_uncharged' => ['severity' => 'medium', 'source' => 'event', 'enabled' => true, 'grace_minutes' => 60],
        'rate_below_floor' => ['severity' => 'medium', 'source' => 'scan', 'enabled' => true, 'percent' => 20, 'floor_amount' => 0],
        'reopened_check' => ['severity' => 'medium', 'source' => 'event', 'enabled' => true, 'min_amount' => 0],
        'after_hours_posting' => ['severity' => 'low', 'source' => 'event', 'enabled' => true, 'start' => '23:00', 'end' => '05:00'],
    ];

    /** Effective config of every rule for the active hotel. */
    public static function forHotel(?Setting $s = null): array
    {
        $s ??= Setting::first();
        $out = [];
        foreach (self::ALL as $rule => $def) {
            $out[$rule] = array_merge($def, array_intersect_key((array) ($s?->rules[$rule] ?? []), $def));
        }

        return $out;
    }

    public static function get(string $rule): ?array
    {
        $cfg = self::forHotel()[$rule] ?? null;

        return $cfg && $cfg['enabled'] ? $cfg : null;
    }
}

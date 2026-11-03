<?php

namespace App\Modules\ServiceRequests\Application;

use App\Models\User;
use App\Modules\ServiceRequests\Domain\VoicePref;

/** One user's voice settings, stored on the server so they follow the user to any device. */
final class VoicePrefs
{
    public const DEFAULTS = [
        'enabled' => true, 'departments' => null, 'repeat_seconds' => 0,
        'quiet_enabled' => false, 'quiet_start' => '22:00', 'quiet_end' => '06:00',
        'volume' => 1.0, 'rate' => 1.0, 'voice' => '', 'chime' => true,
    ];

    /** Departments a user hears when they have not chosen: the ones their permissions work in; managers hear all. */
    public static function defaultDepartments(User $u): array
    {
        $map = ['housekeeping' => 'housekeeping.', 'maintenance' => 'maintenance.', 'fnb' => 'pos.', 'front_office' => 'frontdesk.'];
        $out = [];
        foreach ($map as $dept => $prefix) {
            if ($u->can($prefix.'view') || $u->can($prefix.'manage')) {
                $out[] = $dept;
            }
        }

        return $out ?: ($u->can('servicerequests.manage') ? Catalog::DEPARTMENTS : []);
    }

    /** Stored settings over defaults; `departments` is always a concrete list. */
    public static function for(User $u): array
    {
        $stored = VoicePref::where('user_id', $u->id)->first()?->settings ?? [];
        $s = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $s['departments'] = is_array($s['departments']) ? array_values(array_intersect($s['departments'], Catalog::DEPARTMENTS)) : self::defaultDepartments($u);

        return $s;
    }

    public static function save(User $u, array $data): array
    {
        $current = VoicePref::where('user_id', $u->id)->first();
        $settings = array_merge($current?->settings ?? [], array_intersect_key($data, self::DEFAULTS));
        VoicePref::updateOrCreate(['user_id' => $u->id], ['settings' => $settings]);

        return self::for($u);
    }
}

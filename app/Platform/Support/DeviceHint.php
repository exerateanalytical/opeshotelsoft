<?php

namespace App\Platform\Support;

use Illuminate\Http\Request;

/**
 * First-render device hint from the User-Agent (docs/DEVICE-CERTIFICATION.md).
 * Used only to pre-set <html data-device> and the Inertia `device` prop; the inline script in app.blade.php
 * replaces it before first paint with the client rule (viewport width plus pointer and hover).
 */
final class DeviceHint
{
    public const PHONE = 'phone';

    public const TABLET = 'tablet';

    public const DESKTOP = 'desktop';

    public static function fromUserAgent(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return self::DESKTOP;
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|SM-T\d|Nexus (7|9|10)/i', $ua)
            || (preg_match('/Android/i', $ua) && ! preg_match('/Mobile/i', $ua))) {
            return self::TABLET;
        }
        if (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|Opera Mini|IEMobile|BlackBerry/i', $ua)) {
            return self::PHONE;
        }

        return self::DESKTOP;
    }

    public static function fromRequest(Request $request): string
    {
        return self::fromUserAgent($request->userAgent());
    }
}

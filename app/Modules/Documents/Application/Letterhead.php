<?php

namespace App\Modules\Documents\Application;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Per-hotel letterhead shared by every PDF (documents::partials.letterhead).
 * The logo is inlined as a data URI: dompdf never fetches remote files.
 */
final class Letterhead
{
    public const DEFAULT_COLOR = '#1f2937';

    public const DEFAULT_ACCENT = '#6b7280';

    public const LOGO_DISK = 'local';

    public const DESIGNS = ['classic', 'modern', 'executive', 'minimal', 'heritage', 'corporate', 'resort'];

    public static function design(?Hotel $hotel): string
    {
        $d = $hotel?->settings['letterhead_design'] ?? null;

        return in_array($d, self::DESIGNS, true) ? $d : 'classic';
    }

    public static function for(?Hotel $hotel = null): array
    {
        if (! $hotel) {
            $id = app(TenantContext::class)->hotelId();
            $hotel = $id ? Hotel::find($id) : null;
        }
        $color = fn (?string $c, string $d) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $d;

        return self::derive([
            'name' => $hotel?->legal_name ?: $hotel?->name,
            'trade_name' => $hotel?->legal_name && $hotel->legal_name !== $hotel->name ? $hotel->name : null,
            'address' => trim(implode(' ', array_filter([$hotel?->address, $hotel?->city]))),
            'postal_box' => $hotel?->postal_box,
            'phone' => $hotel?->phone,
            'email' => $hotel?->email,
            'niu' => $hotel?->tax_id,
            'rccm' => $hotel?->trade_register,
            'bank_details' => $hotel?->bank_details,
            'footer' => $hotel?->document_footer,
            'color' => $color($hotel?->brand_color, self::DEFAULT_COLOR),
            'accent' => $color($hotel?->accent_color, self::DEFAULT_ACCENT),
            'logo' => self::logoDataUri($hotel),
            'design' => self::design($hotel),
        ]);
    }

    /** Text-safe variants of the brand colours (readable on white paper and in black and white). Call again after overriding color/accent. */
    public static function derive(array $lh): array
    {
        $lh['color_text'] = self::darkenFor($lh['color'], 0.3);
        $lh['accent_text'] = self::darkenFor($lh['accent'], 0.2);
        $lh['on_color'] = self::luminance($lh['color']) > 0.4 ? '#111827' : '#ffffff';

        return $lh;
    }

    /** WCAG relative luminance of a #rrggbb colour (0 black, 1 white). */
    public static function luminance(string $hex): float
    {
        $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** Darken a colour until it reads on white paper (text use), also when printed in black and white. */
    public static function darkenFor(string $hex, float $maxLuminance): string
    {
        for ($i = 0; $i < 20 && self::luminance($hex) > $maxLuminance; $i++) {
            $hex = sprintf('#%02x%02x%02x', (int) (hexdec(substr($hex, 1, 2)) * 0.85), (int) (hexdec(substr($hex, 3, 2)) * 0.85), (int) (hexdec(substr($hex, 5, 2)) * 0.85));
        }

        return $hex;
    }

    public static function logoDataUri(?Hotel $hotel): ?string
    {
        $path = $hotel?->logo_path;
        if (! $path || ! Storage::disk(self::LOGO_DISK)->exists($path)) {
            return null;
        }
        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

        return "data:$mime;base64,".base64_encode(Storage::disk(self::LOGO_DISK)->get($path));
    }
}

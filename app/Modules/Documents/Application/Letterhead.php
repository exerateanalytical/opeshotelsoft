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

        return [
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
        ];
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

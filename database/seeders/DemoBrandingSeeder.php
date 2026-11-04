<?php

namespace Database\Seeders;

use App\Modules\Documents\Application\Letterhead;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/** Demo hotels get a logo (the OPES emblem, resized), brand colours and a generated seal, so printed documents look finished. */
class DemoBrandingSeeder extends Seeder
{
    private const COLOURS = [['#0f2a4a', '#8a6d1f'], ['#14532d', '#92400e'], ['#7f1d1d', '#374151'], ['#1e3a8a', '#065f46'], ['#312e81', '#9a3412'], ['#134e4a', '#78350f']];

    private const DESIGNS = ['classic', 'modern', 'executive', 'heritage', 'corporate', 'resort', 'minimal'];

    public function run(): void
    {
        $src = base_path('logo/Luxury Hotel Emblem with Gold Skyline.png');
        if (! is_file($src) || ! function_exists('imagecreatefrompng')) {
            return;
        }
        $logo = $this->resize($src, 360);
        $disk = Storage::disk(Letterhead::LOGO_DISK);

        app(TenantContext::class)->withoutScope(function () use ($logo, $disk) {
            foreach (Hotel::orderBy('id')->get() as $i => $hotel) {
                [$color, $accent] = self::COLOURS[$i % count(self::COLOURS)];
                $logoPath = "branding/{$hotel->id}/logo-demo.png";
                $sealPath = "branding/{$hotel->id}/seal-demo.png";
                $disk->put($logoPath, $logo);
                $disk->put($sealPath, $this->seal($hotel->name, $color));
                $hotel->forceFill([
                    'logo_path' => $logoPath, 'brand_color' => $color, 'accent_color' => $accent,
                    'settings' => array_merge($hotel->settings ?? [], ['seal_path' => $sealPath, 'letterhead_design' => $hotel->settings['letterhead_design'] ?? self::DESIGNS[$i % count(self::DESIGNS)]]),
                ])->save();
            }
        });
    }

    private function resize(string $file, int $w): string
    {
        $im = imagecreatefrompng($file);
        $h = (int) round(imagesy($im) * $w / imagesx($im));
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    /** A round two-ring stamp with the hotel name (GD built-in font: no font file needed). */
    private function seal(string $name, string $hex): string
    {
        $n = 300;
        $im = imagecreatetruecolor($n, $n);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        $c = imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        imagesetthickness($im, 6);
        imageellipse($im, $n / 2, $n / 2, $n - 10, $n - 10, $c);
        imagesetthickness($im, 3);
        imageellipse($im, $n / 2, $n / 2, $n - 50, $n - 50, $c);
        $label = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name);
        $label = mb_strimwidth($label, 0, 22, '');
        imagestring($im, 5, (int) (($n - imagefontwidth(5) * strlen($label)) / 2), (int) ($n / 2 - 8), $label, $c);
        imagestring($im, 3, (int) (($n - imagefontwidth(3) * 10) / 2), (int) ($n / 2 + 24), 'CERTIFIED', $c);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}

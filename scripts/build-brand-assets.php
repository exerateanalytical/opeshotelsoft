<?php
// Regenerates brand assets from logo/ into public/brand and public. Usage: php scripts/build-brand-assets.php
$root = dirname(__DIR__);
$src = $root.'/logo';
$emblemSrc = $src.'/Luxury Hotel Emblem with Gold Skyline.png';
$wordSrc = $src.'/Opes H'."\xC3\xB4".'tels Soft Luxury Logo.png';
if (! is_file($emblemSrc) || ! is_file($wordSrc)) { fwrite(STDERR, "Source logos missing in logo/\n"); exit(1); }

function load(string $p) { $i = imagecreatefrompng($p); imagealphablending($i, false); imagesavealpha($i, true); return $i; }
function trim_alpha($im, int $threshold = 12) {
    $w = imagesx($im); $h = imagesy($im);
    $x0 = $w; $y0 = $h; $x1 = 0; $y1 = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $a = (imagecolorat($im, $x, $y) >> 24) & 0x7F;      // 0 opaque .. 127 transparent
        if ((127 - $a) * 2 > $threshold) { if ($x < $x0) $x0 = $x; if ($x > $x1) $x1 = $x; if ($y < $y0) $y0 = $y; if ($y > $y1) $y1 = $y; }
    }
    $out = imagecreatetruecolor($x1 - $x0 + 1, $y1 - $y0 + 1);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopy($out, $im, 0, 0, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1);
    return $out;
}
function blank(int $w, int $h, bool $white = false) {
    $c = imagecreatetruecolor($w, $h);
    imagealphablending($c, false); imagesavealpha($c, true);
    imagefill($c, 0, 0, $white ? imagecolorallocatealpha($c, 255, 255, 255, 0) : imagecolorallocatealpha($c, 0, 0, 0, 127));
    return $c;
}
function scale($im, int $w, ?int $h = null) {
    $h ??= (int) round(imagesy($im) * $w / imagesx($im));
    $o = blank($w, $h);
    imagealphablending($o, false);
    imagecopyresampled($o, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
    return $o;
}
/** Square canvas, image fitted with padding fraction. */
function square($im, int $size, bool $white, float $pad = 0.0) {
    $c = blank($size, $size, $white);
    $inner = (int) round($size * (1 - 2 * $pad));
    $s = scale($im, $inner, (int) round(imagesy($im) * $inner / imagesx($im)));
    imagealphablending($c, true);
    imagecopy($c, $s, (int) (($size - imagesx($s)) / 2), (int) (($size - imagesy($s)) / 2), 0, 0, imagesx($s), imagesy($s));
    imagealphablending($c, false);
    return $c;
}
function save($im, string $p): void { imagepng($im, $p, 9); echo "wrote ".str_replace(dirname(__DIR__).'/', '', $p)." (".imagesx($im)."x".imagesy($im).")\n"; }

$emblem = trim_alpha(load($emblemSrc));
$word = trim_alpha(load($wordSrc));
$b = $root.'/public/brand'; $p = $root.'/public';
@mkdir($b, 0777, true);

$e512 = square($emblem, 512, false, 0.02);
save($e512, $b.'/emblem.png');
save(scale($e512, 128), $b.'/emblem-128.png');
save(scale($e512, 64), $b.'/emblem-64.png');
// Shown at most 280 CSS px wide (Login), so 600 px covers 2x screens at a quarter of the 1200 px weight.
save(scale($word, 600), $b.'/logo-wordmark.png');
save(scale($word, 480), $b.'/logo-wordmark-small.png');

// OG image 1200x630 white, wordmark centred
$og = blank(1200, 630, true);
$w = scale($word, 900);
imagealphablending($og, true);
imagecopy($og, $w, (int) ((1200 - imagesx($w)) / 2), (int) ((630 - imagesy($w)) / 2), 0, 0, imagesx($w), imagesy($w));
save($og, $b.'/og-image.png');

// Favicons / app icons (emblem on transparent for favicons, on white for apple touch)
save(square($emblem, 32, false, 0.0), $p.'/favicon-32.png');
save(square($emblem, 180, true, 0.1), $p.'/apple-touch-icon.png');
save(square($emblem, 192, false, 0.04), $p.'/icon-192.png');
save(square($emblem, 512, false, 0.04), $p.'/icon-512.png');

// Multi-size ICO with PNG payloads
$sizes = [16, 32, 48]; $blobs = [];
foreach ($sizes as $s) { ob_start(); imagepng(square($emblem, $s, false, 0.0), null, 9); $blobs[$s] = ob_get_clean(); }
$ico = pack('vvv', 0, 1, count($sizes)); $offset = 6 + 16 * count($sizes); $data = '';
foreach ($sizes as $s) { $len = strlen($blobs[$s]); $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, $len, $offset); $offset += $len; $data .= $blobs[$s]; }
file_put_contents($p.'/favicon.ico', $ico.$data); echo "wrote public/favicon.ico (16/32/48)\n";

file_put_contents($p.'/site.webmanifest', json_encode([
    'name' => 'OPES H'."\u{F4}".'tels Soft', 'short_name' => 'OPES', 'description' => 'The Hotel Operating System',
    'start_url' => '/', 'display' => 'standalone', 'background_color' => '#ffffff', 'theme_color' => '#0b1a33',
    'icons' => [
        ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "wrote public/site.webmanifest\n";

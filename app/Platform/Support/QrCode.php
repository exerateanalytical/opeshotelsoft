<?php

namespace App\Platform\Support;

use InvalidArgumentException;

/**
 * Minimal QR code encoder (ISO/IEC 18004), byte mode, error correction level L,
 * versions 1-5 (single Reed-Solomon block, up to 106 bytes). Enough for a card
 * token or a pay link and avoids an extra dependency. Output: boolean matrix or SVG.
 */
final class QrCode
{
    /** version => [total codewords, ec codewords per block, alignment centre or null] */
    private const VERSIONS = [1 => [26, 7, null], 2 => [44, 10, 18], 3 => [70, 15, 22], 4 => [100, 20, 26], 5 => [134, 26, 30]];

    /** @return bool[][] rows of modules, true = dark */
    public static function matrix(string $text): array
    {
        $len = strlen($text);
        $version = null;
        foreach (self::VERSIONS as $v => [$total, $ec]) {
            if ($len + 2 <= $total - $ec) { // 4-bit mode + 8-bit count + data fits in data codewords
                $version = $v;
                break;
            }
        }
        if (! $version) {
            throw new InvalidArgumentException('Text too long for the QR encoder.');
        }
        [$total, $ecLen, $align] = self::VERSIONS[$version];
        $dataLen = $total - $ecLen;
        $size = 17 + 4 * $version;

        // Data bits.
        $bits = [];
        $push = function (int $val, int $n) use (&$bits) {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(0b0100, 4);
        $push($len, 8);
        foreach (str_split($text) as $ch) {
            $push(ord($ch), 8);
        }
        $push(0, min(4, $dataLen * 8 - count($bits)));
        while (count($bits) % 8) {
            $bits[] = 0;
        }
        $data = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $data[] = bindec(implode('', $byte));
        }
        for ($pad = 0xEC; count($data) < $dataLen; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }
        $codewords = array_merge($data, self::reedSolomon($data, $ecLen));

        $m = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = function (int $x, int $y, bool $dark) use (&$m, &$fn) {
            $m[$y][$x] = $dark;
            $fn[$y][$x] = true;
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                        $d = max(abs($dx), abs($dy));
                        $set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        if ($align) {
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $set($align + $dx, $align + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
        $mask = 0;
        self::formatBits($set, $size, $mask); // reserve format areas (redrawn below)

        // Zig-zag data placement.
        $i = 0;
        $nbits = count($codewords) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $size - 1 - $vert : $vert;
                    if (! $fn[$y][$x]) {
                        $dark = $i < $nbits && (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $m[$y][$x] = $dark !== (($x + $y) % 2 === 0); // mask 0
                        $i++;
                    }
                }
            }
        }
        self::formatBits($set, $size, $mask);

        return $m;
    }

    public static function svg(string $text, int $scale = 4, int $quiet = 4): string
    {
        $m = self::matrix($text);
        $n = count($m) + 2 * $quiet;
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + $quiet).','.($y + $quiet).'h1v1h-1z';
                }
            }
        }
        $px = $n * $scale;

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$px\" height=\"$px\" viewBox=\"0 0 $n $n\" shape-rendering=\"crispEdges\"><rect width=\"$n\" height=\"$n\" fill=\"#fff\"/><path d=\"$path\" fill=\"#000\"/></svg>";
    }

    /**
     * PNG bytes of the code (GD). Used for PDFs: dompdf's SVG path reads the image back from a
     * temp file and divides by its size, which crashed intermittently; a raster has no such path.
     */
    public static function png(string $text, int $scale = 4, int $quiet = 4): string
    {
        $m = self::matrix($text);
        $n = count($m) + 2 * $quiet;
        $img = imagecreate($n * $scale, $n * $scale);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $n * $scale - 1, $n * $scale - 1, $white);
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    imagefilledrectangle($img, ($x + $quiet) * $scale, ($y + $quiet) * $scale, ($x + $quiet + 1) * $scale - 1, ($y + $quiet + 1) * $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private static function formatBits(callable $set, int $size, int $mask): void
    {
        $data = (0b01 << 3) | $mask; // EC level L
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn (int $i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true); // dark module
    }

    /** @param int[] $data */
    private static function reedSolomon(array $data, int $degree): array
    {
        $mul = function (int $x, int $y): int {
            $z = 0;
            for ($i = 7; $i >= 0; $i--) {
                $z = ($z << 1) ^ (($z >> 7) * 0x11D);
                $z ^= (($y >> $i) & 1) * $x;
            }

            return $z;
        };
        $div = array_fill(0, $degree, 0);
        $div[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $div[$j] = $mul($div[$j], $root);
                if ($j + 1 < $degree) {
                    $div[$j] ^= $div[$j + 1];
                }
            }
            $root = $mul($root, 0x02);
        }
        $res = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($res);
            $res[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $res[$i] ^= $mul($div[$i], $factor);
            }
        }

        return $res;
    }
}

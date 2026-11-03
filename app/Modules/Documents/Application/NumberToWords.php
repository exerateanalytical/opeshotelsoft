<?php

namespace App\Modules\Documents\Application;

/** Integer amounts in words, French and English (for "arrêté la présente facture à la somme de ..."). */
final class NumberToWords
{
    private const FR = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

    private const FR_TENS = [2 => 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

    private const EN = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const EN_TENS = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    public static function convert(int $n, string $lang = 'fr'): string
    {
        $neg = $n < 0;
        $n = abs($n);
        $words = $lang === 'en' ? self::en($n) : self::fr($n);

        return ($neg ? ($lang === 'en' ? 'minus ' : 'moins ') : '').$words;
    }

    private static function fr(int $n): string
    {
        if ($n === 0) {
            return 'zéro';
        }
        $out = [];
        foreach ([1_000_000_000 => ['milliard', 'milliards'], 1_000_000 => ['million', 'millions'], 1000 => ['mille', 'mille']] as $size => $names) {
            if ($n >= $size) {
                $q = intdiv($n, $size);
                $n %= $size;
                $out[] = $size === 1000 ? ($q === 1 ? 'mille' : self::frBelow1000($q, false).' mille') : self::frBelow1000($q, false).' '.($q > 1 ? $names[1] : $names[0]);
            }
        }
        if ($n > 0) {
            $out[] = self::frBelow1000($n, true);
        }

        return implode(' ', $out);
    }

    private static function frBelow1000(int $n, bool $final): string
    {
        $parts = [];
        $h = intdiv($n, 100);
        $r = $n % 100;
        if ($h > 0) {
            $parts[] = $h === 1 ? 'cent' : self::FR[$h].' cent'.($r === 0 && $final ? 's' : '');
        }
        if ($r > 0) {
            $parts[] = self::frBelow100($r, $final);
        }

        return implode(' ', $parts);
    }

    private static function frBelow100(int $n, bool $final): string
    {
        if ($n < 20) {
            return self::FR[$n];
        }
        $t = intdiv($n, 10);
        $u = $n % 10;
        if ($t === 7 || $t === 9) {
            $u += 10;
        }
        $word = self::FR_TENS[$t];
        if ($u === 0) {
            return $word.($t === 8 && $final ? 's' : '');
        }
        if (($u === 1 || $u === 11) && $t < 8) {
            return $word.' et '.self::FR[$u];
        }

        return $word.'-'.self::FR[$u];
    }

    private static function en(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $out = [];
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $size => $name) {
            if ($n >= $size) {
                $out[] = self::enBelow1000(intdiv($n, $size)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $out[] = self::enBelow1000($n);
        }

        return implode(' ', $out);
    }

    private static function enBelow1000(int $n): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = self::EN[intdiv($n, 100)].' hundred';
            $n %= 100;
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::EN[$n] : self::EN_TENS[intdiv($n, 10)].($n % 10 ? '-'.self::EN[$n % 10] : '');
        }

        return implode(' ', $parts);
    }
}

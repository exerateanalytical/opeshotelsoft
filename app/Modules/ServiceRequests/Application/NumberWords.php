<?php

namespace App\Modules\ServiceRequests\Application;

/** Whole numbers 0..999 999 in French and English words (so room numbers are read naturally). */
final class NumberWords
{
    private const FR_UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];

    private const FR_TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    private const EN_UNITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const EN_TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    public static function of(int $n, string $locale): string
    {
        return $locale === 'en' ? self::en($n) : self::fr($n);
    }

    public static function fr(int $n): string
    {
        if ($n < 0) {
            return 'moins '.self::fr(-$n);
        }
        if ($n >= 1_000_000) {
            return (string) $n;
        }
        if ($n >= 1000) {
            $th = intdiv($n, 1000);
            $rest = $n % 1000;
            $head = $th === 1 ? 'mille' : ($th < 1000 ? self::fr999($th, false) : self::fr($th)).' mille';

            return $rest ? "$head ".self::fr999($rest, true) : $head;
        }

        return self::fr999($n, true);
    }

    /** $final: the number ends the whole expression, so "vingt" in quatre-vingts and "cent" in deux cents take the s. */
    private static function fr999(int $n, bool $final): string
    {
        if ($n >= 100) {
            $h = intdiv($n, 100);
            $rest = $n % 100;
            $head = $h === 1 ? 'cent' : self::fr99($h, false).' cent'.(($rest === 0 && $final) ? 's' : '');

            return $rest ? "$head ".self::fr99($rest, $final) : $head;
        }

        return self::fr99($n, $final);
    }

    private static function fr99(int $n, bool $final): string
    {
        if ($n <= 16) {
            return self::FR_UNITS[$n];
        }
        if ($n < 20) {
            return 'dix-'.self::FR_UNITS[$n - 10];
        }
        $t = intdiv($n, 10);
        $u = $n % 10;
        if ($t <= 6) {
            $ten = self::FR_TENS[$t];

            return match (true) {
                $u === 0 => $ten,
                $u === 1 => "$ten et un",
                default => "$ten-".self::FR_UNITS[$u],
            };
        }
        if ($t === 7) {
            return $u === 1 ? 'soixante et onze' : 'soixante-'.self::fr99(10 + $u, false);
        }
        if ($t === 8) {
            return $u === 0 ? 'quatre-vingt'.($final ? 's' : '') : 'quatre-vingt-'.self::FR_UNITS[$u];
        }

        return 'quatre-vingt-'.self::fr99(10 + $u, false);
    }

    public static function en(int $n): string
    {
        if ($n < 0) {
            return 'minus '.self::en(-$n);
        }
        if ($n >= 1_000_000) {
            return (string) $n;
        }
        if ($n >= 1000) {
            $rest = $n % 1000;

            return self::en(intdiv($n, 1000)).' thousand'.($rest ? ' '.self::en($rest) : '');
        }
        if ($n >= 100) {
            $rest = $n % 100;

            return self::EN_UNITS[intdiv($n, 100)].' hundred'.($rest ? ' '.self::en($rest) : '');
        }
        if ($n < 20) {
            return self::EN_UNITS[$n];
        }

        return self::EN_TENS[intdiv($n, 10)].($n % 10 ? '-'.self::EN_UNITS[$n % 10] : '');
    }
}

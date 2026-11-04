<?php

namespace App\Modules\Documents\Application;

/**
 * Words printed on documents that come from stored codes (statuses, departments, settlement bases), translated by the
 * document language so a French document never prints an English code and the other way round. Keys: lang/{fr,en}/documents5.json.
 */
final class DocWords
{
    /** Columns / labels whose text value is a code worth translating. */
    public const COLUMN_KEYS = ['status', 'department', 'kind', 'type'];

    /** English labels of meta rows whose value is a code. */
    public const META_LABELS = ['Status', 'Day status', 'Department', 'Issued to department', 'Type', 'Kind'];

    public static function word(mixed $value, string $lang): string
    {
        $raw = (string) $value;
        if ($raw === '' || ! preg_match('/^[a-z][a-z_]*$/', $raw)) {
            return $raw;
        }
        $key = 'documents5.word.'.$raw;
        $out = trans()->get($key, [], $lang === 'en' ? 'en' : 'fr');

        return is_string($out) && $out !== $key ? $out : $raw;
    }

    /** Settlement "basis" strings are stored in English by People; render them by document language. */
    public static function basis(string $basis, string $lang): string
    {
        $l = $lang === 'en' ? 'en' : 'fr';
        $num = fn ($n) => $l === 'fr' ? str_replace('.', ',', (string) $n) : (string) $n;
        $money = fn ($n) => number_format((int) $n, 0, ',', ' ');
        $plural = fn ($n) => ($l === 'fr' ? (float) $n < 2 : (float) $n === 1.0) ? 'one' : 'other';
        $get = fn (string $k, array $p) => trans()->get('documents5.basis.'.$k, $p, $l);

        if (preg_match('/^([\d.]+) month\(s\) × (\d+)( owed by employee)?$/u', $basis, $m)) {
            return $get(($m[3] ?? '') !== '' ? 'notice_owed_'.$plural($m[1]) : 'notice_'.$plural($m[1]), ['n' => $num($m[1]), 'salary' => $money($m[2])]);
        }
        if (preg_match('/^([\d.]+) day\(s\) × monthly \/ (\d+)$/u', $basis, $m)) {
            return $get('days_'.$plural($m[1]), ['n' => $num($m[1]), 'div' => $m[2]]);
        }
        if (preg_match('/^([\d.]+) year\(s\), banded 20\/25\/30\/35 %$/u', $basis, $m)) {
            return $get('severance_'.$plural($m[1]), ['n' => $num($m[1])]);
        }

        return $basis;
    }
}

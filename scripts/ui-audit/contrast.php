<?php
/**
 * Contrast checker for the design tokens in resources/css/app.css.
 *
 * Parses `--c-*: #hex;` custom properties from the :root block and every
 * `ui-pair: --fg | --bg | min` declaration, prints the ratio of each pair and
 * exits 1 when a pair is under its minimum (4.5 for text, 3 for UI parts).
 *
 *   php scripts/ui-audit/contrast.php
 *
 * The functions are also used by tests/Feature/Platform/UiStandardTest.php.
 */

function ui_parse_tokens(string $css): array
{
    $tokens = [];
    if (preg_match_all('/(--c-[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})\b/', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $tokens[$row[1]] = strtolower($row[2]);
        }
    }
    return $tokens;
}

function ui_parse_pairs(string $css): array
{
    $pairs = [];
    if (preg_match_all('/ui-pair:\s*(--[a-z0-9-]+)\s*\|\s*(--[a-z0-9-]+)\s*\|\s*([0-9.]+)/', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $pairs[] = [$row[1], $row[2], (float) $row[3]];
        }
    }
    return $pairs;
}

function ui_luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    foreach ($c as $i => $v) {
        $v /= 255;
        $c[$i] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function ui_ratio(string $a, string $b): float
{
    $la = ui_luminance($a);
    $lb = ui_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** @return array<int, array{fg:string,bg:string,ratio:float,min:float,ok:bool,missing:bool}> */
function ui_check(string $css): array
{
    $tokens = ui_parse_tokens($css);
    $out = [];
    foreach (ui_parse_pairs($css) as [$fg, $bg, $min]) {
        if (! isset($tokens[$fg], $tokens[$bg])) {
            $out[] = ['fg' => $fg, 'bg' => $bg, 'ratio' => 0.0, 'min' => $min, 'ok' => false, 'missing' => true];
            continue;
        }
        $r = ui_ratio($tokens[$fg], $tokens[$bg]);
        $out[] = ['fg' => $fg, 'bg' => $bg, 'ratio' => $r, 'min' => $min, 'ok' => $r >= $min, 'missing' => false];
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $css = file_get_contents(__DIR__ . '/../../resources/css/app.css');
    $fail = 0;
    foreach (ui_check($css) as $r) {
        printf("%-4s %-28s on %-26s %5.2f (min %.1f)%s\n", $r['ok'] ? 'OK' : 'FAIL', $r['fg'], $r['bg'], $r['ratio'], $r['min'], $r['missing'] ? ' MISSING TOKEN' : '');
        $fail += $r['ok'] ? 0 : 1;
    }
    echo $fail ? "\n$fail pair(s) failed\n" : "\nAll pairs pass\n";
    exit($fail ? 1 : 0);
}

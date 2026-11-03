<?php

namespace App\Modules\Notifications\Application;

final class TemplateRenderer
{
    /** Replace {{ name }} placeholders; unknown variables are left visible so mistakes show in previews. */
    public static function render(?string $text, array $vars): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($vars) {
            $v = data_get($vars, $m[1]);

            return is_scalar($v) ? (string) $v : $m[0];
        }, $text);
    }

    /** @return string[] variable names used in the text */
    public static function variables(string $text): array
    {
        preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }
}

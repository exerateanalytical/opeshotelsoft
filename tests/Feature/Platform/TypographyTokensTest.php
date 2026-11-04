<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * L1 typography, numerals and chart kit: the central tokens exist in app.css, nothing is set
 * under 13px through them, weights stay within 400 to 700, and the shared chart frame and
 * number helpers are present.
 */
class TypographyTokensTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(base_path('resources/css/app.css'));
    }

    private function remToPx(string $v): float
    {
        $v = trim($v);
        if (str_ends_with($v, 'rem')) {
            return (float) $v * 16;
        }
        if (str_ends_with($v, 'px')) {
            return (float) $v;
        }

        return 16.0;
    }

    public function test_type_tokens_are_declared(): void
    {
        $css = $this->css();
        foreach (['--type-body', '--type-support', '--type-caption', '--type-table', '--type-section', '--type-page', '--type-kpi', '--lh-body', '--lh-heading'] as $token) {
            $this->assertStringContainsString($token.':', $css, "Missing token $token");
        }
        foreach (['.l1-page-title', '.l1-section-title', '.l1-kpi', '.l1-caption', '.l1-support', '.num'] as $class) {
            $this->assertStringContainsString($class, $css, "Missing class $class");
        }
        $this->assertMatchesRegularExpression('/font-variant-numeric:\s*tabular-nums/', $css);
    }

    public function test_type_tokens_respect_the_13px_floor_and_ranges(): void
    {
        preg_match_all('/--type-([a-z-]+):\s*([0-9.]+(?:rem|px))/', $this->css(), $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        $ranges = ['body' => [15, 16], 'support' => [13, 14], 'caption' => [13, 13], 'table' => [14, 14], 'section' => [18, 22], 'page' => [20, 32], 'page-body' => [24, 32], 'kpi' => [26, 40]];
        foreach ($m as [, $name, $value]) {
            $px = $this->remToPx($value);
            $this->assertGreaterThanOrEqual(13, $px, "--type-$name is under 13px");
            if (isset($ranges[$name])) {
                [$lo, $hi] = $ranges[$name];
                $this->assertTrue($px >= $lo && $px <= $hi, "--type-$name is {$px}px, expected $lo to $hi");
            }
        }
    }

    public function test_no_thin_font_weights(): void
    {
        $this->assertDoesNotMatchRegularExpression('/font-weight:\s*[123]00\b/', $this->css());
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/js')));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.tsx$/', $f->getFilename()) && preg_match('/\bfont-(thin|extralight|light)\b/', file_get_contents($f->getPathname()))) {
                $hits[] = $f->getFilename();
            }
        }
        $this->assertSame([], $hits, 'Thin font weights are not allowed');
    }

    public function test_chart_kit_and_number_helpers_exist(): void
    {
        $this->assertFileExists(base_path('resources/js/Components/ui/ChartFrame.tsx'));
        $utils = file_get_contents(base_path('resources/js/lib/utils.ts'));
        foreach (['formatCurrency', 'formatNumber', 'formatPercent', 'formatCompact', 'formatDayShort'] as $fn) {
            $this->assertStringContainsString("export function $fn", $utils);
        }
        $css = $this->css();
        foreach (range(1, 6) as $i) {
            $this->assertStringContainsString("--chart-$i:", $css);
        }
    }

    public function test_charts_use_the_shared_frame(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/js/Pages')));
        $direct = [];
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.tsx') && str_contains(file_get_contents($f->getPathname()), 'ResponsiveContainer')) {
                $direct[] = $f->getPathname();
            }
        }
        $this->assertSame([], $direct, 'Pages must render charts through ChartFrame');
    }
}

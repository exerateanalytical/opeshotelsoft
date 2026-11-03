<?php

namespace Tests\Feature\Platform;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ui-audit/contrast.php';

/**
 * Guards docs/PLAN-UI-AUDIT.md: no glow, no gradients, no sub-13px text, and every declared
 * colour pair in resources/css/app.css meets WCAG AA. File scans only (no database needed).
 */
class UiStandardTest extends TestCase
{
    private function sources(): array
    {
        $root = dirname(__DIR__, 3) . '/resources';
        $files = [];
        foreach (['js', 'css'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(tsx?|css)$/', $f->getFilename())) {
                    $files[] = $f->getPathname();
                }
            }
        }
        return $files;
    }

    /** @return array<int,string> offending "file:line: text" */
    private function scan(string $regex, ?callable $skipLine = null): array
    {
        $hits = [];
        foreach ($this->sources() as $file) {
            foreach (file($file) as $i => $line) {
                if (preg_match($regex, $line) && (! $skipLine || ! $skipLine($line, $file))) {
                    $hits[] = str_replace(chr(92), '/',substr($file, strlen(dirname(__DIR__, 3)) + 1)) . ':' . ($i + 1) . ': ' . trim(substr($line, 0, 100));
                }
            }
        }
        return $hits;
    }

    public function test_no_glow_patterns(): void
    {
        $banned = [
            'shadow utilities' => '/(?<![\w-])(?:[a-z-]+:)*shadow(?:-(?!none)[a-z0-9\[]+)?(?=[\s"\'`]|$)/',
            'ring utilities' => '/(?<![\w-])(?:[a-z-]+:)*ring(?:-[a-z0-9\[]+)?(?=[\s"\'`]|$)/',
            'gradients' => '/bg-gradient|linear-gradient|radial-gradient|conic-gradient/',
            'blur' => '/(?<![\w-])(?:[a-z-]+:)*(?:backdrop-)?blur(?:-[a-z0-9]+)?(?=[\s"\'`]|$)|blur\(/',
            'drop-shadow' => '/drop-shadow/',
            'pulse and ping' => '/animate-(pulse|ping)/',
            'text-shadow' => '/text-shadow(?!:\s*none)|text-shadow-/',
        ];
        $skip = fn (string $l) => str_contains($l, '--shadow-') || str_contains($l, '--inset-shadow') || str_contains($l, '--drop-shadow') || str_contains($l, '--text-shadow') || str_contains($l, '--blur-') || str_contains($l, 'ui-pair');
        foreach ($banned as $name => $re) {
            $this->assertSame([], $this->scan($re, $skip), "Banned glow pattern: $name");
        }
    }

    public function test_box_shadow_is_always_none(): void
    {
        $this->assertSame([], $this->scan("/box-shadow\s*:(?!\s*none)/"), "box-shadow must be none");
    }

    public function test_no_font_sizes_under_13px(): void
    {
        $re = '/text-\[(?:[0-9]|1[0-2])(?:\.\d+)?px\]|font-?[sS]ize\s*[:=]\s*[\'"]?(?:[0-9]|1[0-2])(?:px)?[\'"]?[,\s}]|font-size\s*:\s*(?:[0-9]|1[0-2])px/';
        $this->assertSame([], $this->scan($re), 'Hard-coded font sizes must be 13px or larger');
    }

    public function test_declared_colour_pairs_meet_wcag_aa(): void
    {
        $css = file_get_contents(dirname(__DIR__, 3) . '/resources/css/app.css');
        $results = ui_check($css);
        $this->assertGreaterThan(20, count($results), 'Contrast pairs must be declared in app.css');
        foreach ($results as $r) {
            $this->assertFalse($r['missing'], "Missing token in pair {$r['fg']} on {$r['bg']}");
            $this->assertGreaterThanOrEqual($r['min'], $r['ratio'], sprintf('%s on %s is %.2f, needs %.1f', $r['fg'], $r['bg'], $r['ratio'], $r['min']));
        }
    }
}

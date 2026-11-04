<?php

namespace Tests\Feature\Platform;

use App\Platform\Support\DeviceHint;
use PHPUnit\Framework\TestCase;

/**
 * Device-class guard (docs/DEVICE-CERTIFICATION.md). File scans and a pure unit, no database.
 * The rendered check (desktop viewports never show the bottom tab bar, phones never show the sidebar)
 * is scripts/ui-audit/device.cjs, which exits non-zero on any wrong-device finding.
 */
class DeviceClassTest extends TestCase
{
    private function file(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/'.$rel);
    }

    public function test_user_agent_hint(): void
    {
        $this->assertSame('phone', DeviceHint::fromUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148'));
        $this->assertSame('phone', DeviceHint::fromUserAgent('Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120 Mobile Safari/537.36'));
        $this->assertSame('tablet', DeviceHint::fromUserAgent('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)'));
        $this->assertSame('tablet', DeviceHint::fromUserAgent('Mozilla/5.0 (Linux; Android 13; SM-X700) Chrome/120 Safari/537.36'));
        $this->assertSame('desktop', DeviceHint::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36'));
        $this->assertSame('desktop', DeviceHint::fromUserAgent(null));
    }

    public function test_device_class_is_set_before_first_paint(): void
    {
        $blade = $this->file('resources/views/app.blade.php');
        $this->assertStringContainsString('data-device="{{ \App\Platform\Support\DeviceHint::fromRequest(request()) }}"', $blade);
        $script = strpos($blade, "setAttribute('data-device'");
        $this->assertNotFalse($script, 'inline device script missing');
        $this->assertLessThan(strpos($blade, '@vite('), $script, 'the device script must run before the stylesheet and app bundle');
        $this->assertStringContainsString('Vite::cspNonce()', $blade, 'inline script needs the CSP nonce');
        $this->assertStringContainsString('(hover: hover) and (pointer: fine)', $blade);
        $this->assertStringContainsString('w>=900', $blade, 'mouse laptops from 900 CSS px are desktops');
    }

    public function test_layout_breakpoints_follow_the_device_class(): void
    {
        $css = $this->file('resources/css/app.css');
        foreach ([
            '@custom-variant md (&:where(html:not([data-device="phone"]) *));',
            '@custom-variant lg (&:where(html[data-device="desktop"] *));',
            '@custom-variant max-lg (&:where(html:not([data-device="desktop"]) *));',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
        $this->assertStringNotContainsString('(max-width: 1023px)', $css, 'touch sizing follows the pointer, layout follows data-device');
        $this->assertStringNotContainsString('@media (min-width: 1024px)', $css, 'desktop rules use html[data-device="desktop"]');
    }

    public function test_no_component_decides_the_device_from_width(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js';
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! preg_match('/\.tsx?$/', $f->getFilename())) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match('/useMediaQuery\(\s*[\'"]\((min|max)-width/', $src)) {
                $bad[] = str_replace($root, '', $f->getPathname());
            }
        }
        $this->assertSame([], $bad, 'use useDevice() from lib/useDevice.ts instead of width media queries');

        $bottom = $this->file('resources/js/Components/layout/BottomNav.tsx');
        $side = $this->file('resources/js/Components/layout/Sidebar.tsx');
        $this->assertStringContainsString('md:hidden', $bottom, 'the tab bar hides outside phones (md: = not phone)');
        $this->assertStringContainsString('hidden md:flex', $side, 'the sidebar hides on phones');
        $layout = $this->file('resources/js/Layouts/AppLayout.tsx');
        $this->assertStringContainsString('useDevice()', $layout);
    }
}

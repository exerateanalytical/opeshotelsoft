<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * N1 desktop-native shell: pages that carry the shared DataTable, palette and split view still render for the key
 * roles, the nav model reaches the palette, and the FR/EN desktop.* files stay in parity.
 */
class DesktopShellTest extends TestCase
{
    private const PAGES = ['/reservations', '/guests', '/billing/invoices', '/billing/folios', '/housekeeping', '/dashboard', '/profile'];

    public function test_key_pages_render_for_general_manager_with_nav_for_palette(): void
    {
        $this->actingAs($this->user, 'web');
        foreach (self::PAGES as $url) {
            $this->get($url, ['Accept' => 'text/html'])->assertOk()
                ->assertInertia(fn ($page) => $page->has('nav')->has('auth.user.permissions'));
        }
    }

    public function test_pages_render_for_a_receptionist(): void
    {
        $user = $this->makeUser($this->group, 'receptionist');
        $this->actingAs($user, 'web');
        foreach (['/reservations', '/guests', '/billing/folios'] as $url) {
            $this->get($url, ['Accept' => 'text/html'])->assertOk();
        }
    }

    public function test_housekeeper_sees_no_billing_entry_in_nav(): void
    {
        $user = $this->makeUser($this->group, 'room-attendant');
        $this->actingAs($user, 'web');
        $this->get('/housekeeping', ['Accept' => 'text/html'])->assertOk()
            ->assertInertia(fn ($page) => $page->where('nav', fn ($nav) => ! collect($nav)->contains(fn ($n) => $n['path'] === '/billing/folios')));
    }

    public function test_desktop_language_files_have_identical_keys_and_no_blank_values(): void
    {
        $en = json_decode(file_get_contents(lang_path('en/desktop.json')), true);
        $fr = json_decode(file_get_contents(lang_path('fr/desktop.json')), true);
        $this->assertIsArray($en);
        $this->assertIsArray($fr);
        $this->assertSame([], array_diff_key($en, $fr), 'keys missing in fr');
        $this->assertSame([], array_diff_key($fr, $en), 'keys missing in en');
        foreach ([$en, $fr] as $set) {
            foreach ($set as $key => $value) {
                $this->assertStringStartsWith('desktop.', $key);
                $this->assertNotSame('', trim($value), $key);
            }
        }
        // Placeholders must match between the languages.
        foreach ($en as $key => $value) {
            preg_match_all('/:[a-z]+/', $value, $a);
            preg_match_all('/:[a-z]+/', $fr[$key], $b);
            $this->assertEqualsCanonicalizing($a[0], $b[0], "placeholders differ for $key");
        }
    }

    public function test_every_desktop_key_used_in_the_source_exists(): void
    {
        $en = json_decode(file_get_contents(lang_path('en/desktop.json')), true);
        $files = array_merge(
            glob(resource_path('js/Components/layout/*.tsx')),
            glob(resource_path('js/Components/ui/*.tsx')),
            glob(resource_path('js/Pages/{Reservations,Guests,Billing,Housekeeping}/*.tsx'), GLOB_BRACE),
        );
        foreach ($files as $file) {
            preg_match_all("/t\\(['\"`](desktop\\.[a-z_.]+)['\"`]/", file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $this->assertArrayHasKey($key, $en, "$key used in ".basename($file));
            }
        }
    }
}

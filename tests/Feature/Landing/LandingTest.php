<?php

namespace Tests\Feature\Landing;

use App\Models\DemoRequest;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LandingTest extends TestCase
{
    private const H = ['Accept' => 'text/html'];

    public function test_visitors_see_the_landing_page_in_french_by_default(): void
    {
        $this->get('/', self::H)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Landing/Index')
            ->where('locale', 'fr')
            ->where('translations', fn ($t) => str_contains($t['landing.hero.title'], 'Tout votre hôtel')));
    }

    public function test_language_switch_gives_english_copy(): void
    {
        $this->post('/locale', ['locale' => 'en']);
        $this->get('/', self::H)->assertInertia(fn (AssertableInertia $p) => $p
            ->where('locale', 'en')
            ->where('translations', fn ($t) => str_contains($t['landing.hero.title'], 'Your whole hotel')));
    }

    public function test_signed_in_staff_go_straight_to_the_dashboard(): void
    {
        $this->actingAs($this->user, 'web')->get('/', self::H)->assertRedirect(route('dashboard'));
    }

    public function test_landing_copy_has_identical_keys_in_both_languages(): void
    {
        $fr = json_decode(file_get_contents(lang_path('fr/landing.json')), true);
        $en = json_decode(file_get_contents(lang_path('en/landing.json')), true);
        $this->assertEqualsCanonicalizing(array_keys($fr), array_keys($en));
        $this->assertNotEmpty($fr);
    }

    public function test_demo_request_is_stored(): void
    {
        $this->post('/demo-request', ['name' => 'Aminata B.', 'hotel' => 'Hôtel du Wouri', 'city' => 'Douala', 'rooms' => 42, 'phone' => '677000000'])->assertRedirect();
        $this->assertDatabaseHas('demo_requests', ['name' => 'Aminata B.', 'hotel' => 'Hôtel du Wouri', 'rooms' => 42, 'status' => 'new']);
    }

    public function test_demo_request_needs_a_name_and_a_way_to_reach_the_person(): void
    {
        $this->withHeaders(self::H)->post('/demo-request', ['name' => 'No contact'])->assertSessionHasErrors(['phone', 'email']);
        $this->withHeaders(self::H)->post('/demo-request', ['phone' => '677000000'])->assertSessionHasErrors('name');
        $this->withHeaders(self::H)->post('/demo-request', ['name' => 'X', 'email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->assertSame(0, DemoRequest::count());
    }

    public function test_bots_filling_the_hidden_field_are_ignored(): void
    {
        $this->post('/demo-request', ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.example'])->assertRedirect();
        $this->assertSame(0, DemoRequest::count());
    }

    public function test_demo_requests_are_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/demo-request', ['name' => "Lead $i", 'email' => "lead$i@example.com"])->assertRedirect();
        }
        $this->post('/demo-request', ['name' => 'Lead 6', 'email' => 'lead6@example.com'])->assertStatus(429);
        $this->assertSame(5, DemoRequest::count());
    }
}

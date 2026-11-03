<?php

namespace Tests\Feature\I18n;

use App\Http\Middleware\SetRequestLocale;
use Illuminate\Http\Request;
use Tests\TestCase;

/** P3: server-sent labels follow the user's saved locale (or Accept-Language); value codes do not change. */
class ServerLabelsTest extends TestCase
{
    private function asLocale(string $locale): void
    {
        $this->user->update(['locale' => $locale]);
    }

    public function test_cash_flow_labels_switch_language_but_keys_stay(): void
    {
        $q = '/api/v1/accounting/reports/cash-flow?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString();

        $this->asLocale('fr');
        $fr = $this->getJson($q)->assertOk()->json('sections');
        $this->assertSame('Flux de trésorerie des activités opérationnelles', $fr[0]['label']);
        $this->assertSame('Résultat net', $fr[0]['lines'][0]['label']);

        $this->asLocale('en');
        $en = $this->getJson($q)->assertOk()->json('sections');
        $this->assertSame('Cash flows from operating activities', $en[0]['label']);
        $this->assertSame('Net result', $en[0]['lines'][0]['label']);
        $this->assertSame(array_column($fr, 'key'), array_column($en, 'key'));
    }

    public function test_dsf_gate_and_report_titles_are_translated(): void
    {
        $this->asLocale('fr');
        $this->assertStringContainsString('expert-comptable', $this->getJson('/api/v1/accounting/reports/dsf?year='.now()->year)->assertOk()->json('gate'));
        $fr = collect($this->getJson('/api/v1/exports')->assertOk()->json())->keyBy('key');
        $this->assertSame('Balance générale', $fr['trial-balance']['title']);

        $this->asLocale('en');
        $this->assertStringContainsString('chartered accountant', $this->getJson('/api/v1/accounting/reports/dsf?year='.now()->year)->assertOk()->json('gate'));
        $en = collect($this->getJson('/api/v1/exports')->assertOk()->json())->keyBy('key');
        $this->assertSame('Trial balance', $en['trial-balance']['title']);
        $this->assertSame($fr->keys()->all(), $en->keys()->all());
    }

    public function test_problem_json_and_validation_messages_are_translated(): void
    {
        $this->asLocale('fr');
        $fr = $this->getJson('/api/v1/accounting/reports/cash-flow')->assertStatus(422);
        $this->assertSame('Données invalides', $fr->json('title'));
        $this->assertSame('Les données fournies sont invalides.', $fr->json('detail'));
        $this->assertStringContainsString('obligatoire', $fr->json('errors.from.0'));

        $this->asLocale('en');
        $en = $this->getJson('/api/v1/accounting/reports/cash-flow')->assertStatus(422);
        $this->assertSame('Invalid data', $en->json('title'));
        $this->assertSame('The given data was invalid.', $en->json('detail'));
        $this->assertStringContainsString('required', $en->json('errors.from.0'));
        $this->assertStringEndsWith('/validation', $en->json('type'));

        $this->getJson('/api/v1/exports/nope?format=csv')->assertStatus(422)->assertJsonPath('title', 'Unknown report');
    }

    public function test_accept_language_is_used_without_a_signed_in_user_and_french_is_the_default(): void
    {
        $run = function (array $headers) {
            $request = Request::create('/api/v1/anything', 'GET', [], [], [], $headers);
            if (! $headers) {
                $request->headers->remove('Accept-Language'); // Request::create adds an English default
            }
            $request->setUserResolver(fn () => null);
            (new SetRequestLocale)->handle($request, fn () => response('ok'));

            return app()->getLocale();
        };

        $this->assertSame('en', $run(['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']));
        $this->assertSame('fr', $run(['HTTP_ACCEPT_LANGUAGE' => 'fr-CM,fr;q=0.9,en;q=0.5']));
        $this->assertSame('fr', $run(['HTTP_ACCEPT_LANGUAGE' => 'de-DE']));
        $this->assertSame('fr', $run([]));
    }

    public function test_server_fragments_have_identical_keys_and_load_through_the_translator(): void
    {
        $fr = json_decode(file_get_contents(lang_path('fr/server.json')), true);
        $en = json_decode(file_get_contents(lang_path('en/server.json')), true);
        $this->assertSame(array_keys($fr), array_keys($en));
        foreach (array_keys($fr) as $key) {
            $this->assertStringStartsWith('srv.', $key);
        }
        $this->assertSame('Bilan', __('srv.report.balance-sheet', [], 'fr'));
        $this->assertSame('Balance sheet', __('srv.report.balance-sheet', [], 'en'));
    }
}

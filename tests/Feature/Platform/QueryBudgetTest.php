<?php

namespace Tests\Feature\Platform;

use App\Modules\Guests\Domain\Guest;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for docs/PERFORMANCE.md: query-count budgets for the hot
 * screens (an N+1 makes the count grow with the data) and the translations
 * dictionary travelling only on the first load and on a locale change.
 * Budgets = count measured on 2026-11-04 plus headroom.
 */
class QueryBudgetTest extends TestCase
{
    private function seedReservations(int $n): void
    {
        [$type, $plan] = $this->seedInventory($n + 2);
        foreach (range(1, $n) as $i) {
            $guest = Guest::create(['first_name' => "G$i", 'last_name' => 'Budget', 'email' => "g$i@budget.test", 'phone' => '+23769000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
            $this->postJson('/api/v1/reservations', [
                'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
                'arrival' => now()->addDays($i % 3)->toDateString(), 'departure' => now()->addDays(3 + $i % 3)->toDateString(),
            ])->assertCreated();
        }
    }

    private function queries(callable $request): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $request();
        if (getenv('QUERY_BUDGET_REPORT')) {
            fwrite(STDERR, "queries: $count\n");
        }

        return $count;
    }

    public function test_api_hot_lists_stay_within_their_query_budgets(): void
    {
        $this->seedReservations(8);
        $from = now()->toDateString();
        $to = now()->addDays(14)->toDateString();

        $budgets = [
            // measured with 8 reservations: 6, 2, 6, 6, 3
            '/api/v1/reservations' => 10,
            '/api/v1/folios' => 6,
            '/api/v1/reports/dashboard/compose' => 12,
            "/api/v1/reservations/tape-chart?from=$from&to=$to" => 10,
            '/api/v1/housekeeping/board' => 6,
        ];
        foreach ($budgets as $url => $budget) {
            $n = $this->queries(fn () => $this->getJson($url)->assertOk());
            $this->assertLessThanOrEqual($budget, $n, "$url ran $n queries (budget $budget)");
        }
    }

    private function inertiaVersion(): string
    {
        return (string) $this->get('/dashboard')->assertOk()->viewData('page')['version'];
    }

    public function test_inertia_shell_stays_within_its_query_budget(): void
    {
        $this->actingAs($this->user, 'web');
        $version = $this->inertiaVersion();
        $n = $this->queries(fn () => $this->get('/reservations', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])->assertOk());
        // measured: 1 in tests (array cache/session); 9 on the demo database with database sessions
        $this->assertLessThanOrEqual(6, $n, "Inertia visit ran $n queries (budget 6)");
    }

    public function test_translations_travel_on_first_load_and_locale_change_only(): void
    {
        $this->actingAs($this->user, 'web');
        $first = $this->get('/dashboard')->assertOk()->viewData('page');
        $this->assertNotEmpty($first['props']['translations'] ?? null, 'first full load carries the translations');
        $key = 'translations.'.$first['props']['locale'];
        $this->assertArrayHasKey($key, $first['onceProps'] ?? []);

        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $first['version'], 'X-Requested-With' => 'XMLHttpRequest'];

        // The client already holds them: an Inertia visit does not send them again.
        $visit = $this->get('/reservations', $headers + ['X-Inertia-Except-Once-Props' => $key])->assertOk()->json();
        $this->assertArrayNotHasKey('translations', $visit['props']);
        $this->assertArrayHasKey($key, $visit['onceProps'], 'the client keeps its copy');

        // A client without them (or after a reload) gets them.
        $fresh = $this->get('/reservations', $headers)->assertOk()->json();
        $this->assertNotEmpty($fresh['props']['translations']);

        // Locale change: the key carries the locale, so the new dictionary is sent.
        $other = $first['props']['locale'] === 'fr' ? 'en' : 'fr';
        $this->user->forceFill(['locale' => $other])->save();
        $switched = $this->get('/reservations', $headers + ['X-Inertia-Except-Once-Props' => $key])->assertOk()->json();
        $this->assertSame($other, $switched['props']['locale']);
        $this->assertNotEmpty($switched['props']['translations'] ?? null, 'locale change sends the new dictionary');
        $this->assertSame(\App\Http\Middleware\HandleInertiaRequests::translations($other), $switched['props']['translations']);
    }
}

<?php

namespace Tests\Feature\Stock;

class OpsStocktakeTest extends OpsTestCase
{
    private function stock3(): array
    {
        $a = $this->item('A', 'Sucre');
        $b = $this->item('B', 'Whisky', 'btl');
        $c = $this->item('C', 'Cafe');
        $this->receive($a, $this->main, 100, 1000);
        $this->receive($b, $this->main, 50, 10_000);
        $this->receive($c, $this->main, 10, 2000);

        return [$a, $b, $c];
    }

    private function start(): array
    {
        return $this->postJson('/api/v1/stock-ops/stocktakes', ['store_id' => $this->main->id, 'tolerance_pct' => 2, 'tolerance_qty' => 0.5])->assertCreated()->json();
    }

    private function counts(array $st, array $counts)
    {
        return $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/counts", ['counts' => collect($counts)->map(fn ($q, $id) => ['stock_item_id' => $id, 'quantity' => $q])->values()->all()]);
    }

    public function test_blind_sheet_hides_expected_quantities_and_review_needs_its_own_permission(): void
    {
        $this->stock3();
        $st = $this->start();
        $this->as('storekeeper');
        $sheet = $this->getJson("/api/v1/stock-ops/stocktakes/{$st['id']}/sheet")->assertOk()->json();
        $this->assertCount(3, $sheet['lines']);
        $this->assertStringNotContainsString('expected', json_encode($sheet));
        $this->assertStringNotContainsString('100', json_encode($sheet['lines']));
        $this->getJson("/api/v1/stock-ops/stocktakes/{$st['id']}/review")->assertForbidden();
        $this->actAs($this->user, $this->hotel);
        $this->getJson("/api/v1/stock-ops/stocktakes/{$st['id']}/review")->assertOk()->assertJsonPath('lines.0.expected', 100);
    }

    public function test_within_tolerance_goes_straight_to_review_without_recount(): void
    {
        [$a, $b, $c] = $this->stock3();
        $st = $this->start();
        $this->as('storekeeper');
        $this->counts($st, [$a->id => 101, $b->id => 50, $c->id => 10.4])->assertOk();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/close-count")->assertOk()->assertJsonPath('status', 'review');
    }

    public function test_incomplete_count_is_refused_and_recount_needs_a_different_person(): void
    {
        [$a, $b, $c] = $this->stock3();
        $st = $this->start();
        $this->as('storekeeper');
        $this->counts($st, [$a->id => 100, $b->id => 40])->assertOk();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/close-count")->assertStatus(422); // coffee not counted
        $this->counts($st, [$c->id => 10])->assertOk();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/close-count")->assertOk()->assertJsonPath('status', 'recount');

        // Recount sheet: only the whisky, without the first count.
        $sheet = $this->getJson("/api/v1/stock-ops/stocktakes/{$st['id']}/sheet")->assertOk()->json();
        $this->assertSame('recount', $sheet['phase']);
        $this->assertCount(1, $sheet['lines']);
        $this->assertNull($sheet['lines'][0]['counted']);

        $this->counts($st, [$b->id => 41])->assertStatus(422); // same counter as the first count
        $this->as('housekeeping-manager');
        $this->counts($st, [$a->id => 100])->assertStatus(422); // sugar was within tolerance
        $this->counts($st, [$b->id => 41])->assertOk();
        $this->actAs($this->user, $this->hotel);
        $this->getJson("/api/v1/stock-ops/stocktakes/{$st['id']}/review")->assertOk()->assertJsonPath('status', 'review')->assertJsonPath('variance_value', -90_000);
    }

    public function test_approval_posts_balanced_adjustments_and_enforces_segregation_of_duties(): void
    {
        [$a, $b, $c] = $this->stock3();
        $st = $this->start(); // started by the general manager
        $this->as('storekeeper');
        $this->counts($st, [$a->id => 100, $b->id => 40, $c->id => 10])->assertOk();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/close-count")->assertOk();
        $this->as('housekeeping-manager');
        $this->counts($st, [$b->id => 41])->assertOk();

        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/approve")->assertStatus(403); // the starter cannot approve
        $this->as('storekeeper');
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/approve")->assertForbidden(); // no permission

        $this->as('finance-director');
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/approve", ['note' => 'Casse constatee'])->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('variance_value', -90_000);
        $this->assertSame(41.0, $this->onHand($this->main, $b));
        $this->assertSame(100.0, $this->onHand($this->main, $a));
        $this->assertBooksBalance();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/approve")->assertStatus(422); // already posted

        $this->assertCount(1, $this->events('stock.stocktake_posted'));
        $leak = $this->events('stock.leakage_suspected');
        $this->assertCount(1, $leak);
        $this->assertSame(90_000, $leak->first()->payload['amount']);
    }

    public function test_surplus_is_posted_and_scan_adds_to_the_count(): void
    {
        [$a, $b, $c] = $this->stock3();
        $st = $this->start();
        $this->as('storekeeper');
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/scan", ['code' => 'ITM:A', 'quantity' => 60])->assertOk();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/scan", ['code' => 'ITM:A', 'quantity' => 55])->assertOk()->assertJsonPath('item.code', 'A');
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/scan", ['code' => 'ITM:NOPE'])->assertStatus(404);
        $this->counts($st, [$b->id => 50, $c->id => 10])->assertOk();
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/close-count")->assertOk()->assertJsonPath('status', 'recount'); // 115 against 100
        $this->as('housekeeping-manager');
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/scan", ['code' => 'ITM:A', 'quantity' => 115])->assertOk();
        $this->as('finance-director');
        $this->postJson("/api/v1/stock-ops/stocktakes/{$st['id']}/approve")->assertOk();
        $this->assertSame(115.0, $this->onHand($this->main, $a));
        $this->assertBooksBalance();
        $this->assertCount(0, $this->events('stock.leakage_suspected'));
    }
}

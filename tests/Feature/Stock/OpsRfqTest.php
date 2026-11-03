<?php

namespace Tests\Feature\Stock;

class OpsRfqTest extends OpsTestCase
{
    private function rfq(): array
    {
        $flour = $this->item('FLR', 'Farine');
        $oil = $this->item('OIL', 'Huile', 'l');
        $a = $this->supplier('SA');
        $b = $this->supplier('SB');
        $c = $this->supplier('SC');
        $rfq = $this->postJson('/api/v1/stock-ops/rfqs', [
            'title' => 'Economat janvier', 'store_id' => $this->main->id, 'supplier_ids' => [$a->id, $b->id, $c->id],
            'lines' => [['stock_item_id' => $flour->id, 'quantity' => 100], ['stock_item_id' => $oil->id, 'quantity' => 40]],
        ])->assertCreated()->json();

        return [$rfq, $a, $b, $c, $flour, $oil];
    }

    private function quotes(array $rfq, $a, $b, $c): void
    {
        [$l1, $l2] = array_column($rfq['lines'], 'id');
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $a->id, 'lines' => [
            ['rfq_line_id' => $l1, 'unit_price' => 800, 'lead_time_days' => 3], ['rfq_line_id' => $l2, 'unit_price' => 1500]]])->assertOk();
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $b->id, 'lines' => [
            ['rfq_line_id' => $l1, 'unit_price' => 750, 'lead_time_days' => 5], ['rfq_line_id' => $l2, 'unit_price' => 1600]]])->assertOk();
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $c->id, 'lines' => [['rfq_line_id' => $l1, 'unit_price' => 900]]])->assertOk();
    }

    public function test_quotes_need_a_sent_rfq_and_an_invited_supplier(): void
    {
        [$rfq, $a] = $this->rfq();
        $line = $rfq['lines'][0]['id'];
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $a->id, 'lines' => [['rfq_line_id' => $line, 'unit_price' => 1]]])->assertStatus(422);
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/send")->assertOk()->assertJsonPath('status', 'sent');
        $other = $this->supplier('SX');
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $other->id, 'lines' => [['rfq_line_id' => $line, 'unit_price' => 1]]])->assertStatus(422);
        $this->assertCount(1, $this->events('stock.rfq_sent'));
    }

    public function test_comparison_grid_flags_lowest_price_and_complete_supplier_totals(): void
    {
        [$rfq, $a, $b, $c] = $this->rfq();
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/send")->assertOk();
        $this->quotes($rfq, $a, $b, $c);

        $grid = $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/grid")->assertOk()->json();
        $this->assertSame(750, $grid['lines'][0]['best_price']);
        $this->assertSame(1500, $grid['lines'][1]['best_price']);
        $lowest = collect($grid['lines'][0]['quotes'])->firstWhere('lowest', true);
        $this->assertSame($b->id, $lowest['supplier_id']);
        $totals = collect($grid['suppliers'])->keyBy('id');
        $this->assertSame(100 * 800 + 40 * 1500, $totals[$a->id]['total']);
        $this->assertFalse($totals[$c->id]['complete']);
        $this->assertSame($b->id, $grid['cheapest_complete_supplier_id']); // B 139000 against A 140000
    }

    public function test_award_per_line_converts_to_one_purchase_order_per_supplier(): void
    {
        [$rfq, $a, $b, $c, $flour, $oil] = $this->rfq();
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/send")->assertOk();
        $this->quotes($rfq, $a, $b, $c);
        [$l1, $l2] = array_column($rfq['lines'], 'id');

        // C never quoted the oil line.
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/award", ['awards' => [['rfq_line_id' => $l2, 'supplier_id' => $c->id]]])->assertStatus(422);
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/award", ['awards' => [['rfq_line_id' => $l1, 'supplier_id' => $b->id], ['rfq_line_id' => $l2, 'supplier_id' => $a->id]]])->assertOk()->assertJsonPath('rfq.status', 'awarded');

        $res = $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/convert")->assertCreated()->json('purchase_orders');
        $this->assertCount(2, $res);
        $byEmpty = collect($res)->keyBy('supplier_id');
        $this->assertSame(100 * 750, (int) $byEmpty[$b->id]['net_total']);
        $this->assertSame(40 * 1500, (int) $byEmpty[$a->id]['net_total']);
        $this->assertSame('draft', $byEmpty[$a->id]['status']);
        $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}")->assertJsonPath('status', 'converted');
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/convert")->assertStatus(422);
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/award", ['supplier_id' => $a->id])->assertStatus(422);
    }

    public function test_award_whole_rfq_to_one_supplier_and_supplier_sheet(): void
    {
        [$rfq, $a, $b, $c] = $this->rfq();
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/send")->assertOk();
        $this->quotes($rfq, $a, $b, $c);
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/award", ['supplier_id' => $c->id])->assertStatus(422); // C quoted one line only
        $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/award", ['supplier_id' => $b->id])->assertOk();
        $this->assertCount(1, $this->postJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/convert")->assertCreated()->json('purchase_orders'));

        $sheet = $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/supplier-sheet/{$a->id}")->assertOk()->json();
        $this->assertCount(2, $sheet['lines']);
        $this->assertSame(800, $sheet['lines'][0]['quoted_price']);
        $this->assertSame('SA', $sheet['supplier']['code']);
    }

    public function test_rfq_actions_are_permission_guarded(): void
    {
        [$rfq] = $this->rfq();
        $this->as('room-attendant');
        $this->postJson('/api/v1/stock-ops/rfqs', ['title' => 'x', 'store_id' => $this->main->id, 'supplier_ids' => [1], 'lines' => [['stock_item_id' => 1, 'quantity' => 1]]])->assertForbidden();
        $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/grid")->assertForbidden();
    }
}

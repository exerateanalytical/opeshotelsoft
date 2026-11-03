<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Domain\PurchaseOrder;

class ControlReorderTest extends ControlTestCase
{
    private function par(int $itemId, float $par, float $min, ?int $supplierId = null, ?int $lead = null): void
    {
        $this->putJson('/api/v1/stock-control/par-levels', [
            'store_id' => $this->main->id, 'stock_item_id' => $itemId, 'par_level' => $par, 'min_level' => $min, 'supplier_id' => $supplierId, 'lead_time_days' => $lead,
        ])->assertCreated();
    }

    public function test_suggestions_group_by_supplier_honour_lead_times_and_convert_to_draft_pos(): void
    {
        $s1 = $this->supplier('S1', 'Moulin SA');
        $s2 = $this->supplier('S2', 'Salines');
        $flour = $this->item('FLR', 'Flour', 'kg', ['purchase_unit' => 'sack', 'purchase_factor' => 25]);
        $salt = $this->item('SALT', 'Salt', 'kg');
        $sugar = $this->item('SUG', 'Sugar', 'kg');
        $spice = $this->item('SPI', 'Spice', 'kg');
        $this->receive($flour, $this->main, 10, 80);
        $this->receive($sugar, $this->main, 50, 90);
        $this->putJson("/api/v1/stock-control/lead-times/{$s1->id}", ['lead_time_days' => 5])->assertSuccessful();
        $this->putJson("/api/v1/stock-control/lead-times/{$s2->id}", ['lead_time_days' => 1])->assertSuccessful();
        $this->par($flour->id, 100, 20, $s1->id);
        $this->par($salt->id, 10, 2, $s2->id);
        $this->par($sugar->id, 100, 40, $s1->id);   // 50 on hand: above its minimum, not suggested
        $this->par($spice->id, 10, 3);               // no supplier

        $groups = collect($this->getJson('/api/v1/stock-control/reorder/suggestions')->assertOk()->json('groups'));
        $this->assertCount(3, $groups);
        $g1 = $groups->firstWhere('supplier_id', $s1->id);
        $this->assertSame(5, $g1['lead_time_days']);
        $this->assertSame($this->hotel->fresh()->business_date->addDays(5)->toDateString(), $g1['expected_date']);
        $this->assertCount(1, $g1['lines']);
        $this->assertSame(4, $g1['lines'][0]['suggested_quantity']);   // (100 - 10) / 25 rounded up to sacks
        $this->assertSame(2_000, $g1['lines'][0]['unit_price']);       // 80 x 25
        $this->assertSame(8_000, $g1['estimated_total']);
        $this->assertSame(1, $groups->firstWhere('supplier_id', $s2->id)['lead_time_days']);

        $res = $this->postJson('/api/v1/stock-control/reorder/convert')->assertCreated();
        $this->assertCount(2, $res->json('orders'));
        $this->assertCount(1, $res->json('skipped'));
        $po = PurchaseOrder::where('supplier_id', $s1->id)->with('lines')->first();
        $this->assertSame('draft', $po->status);
        $this->assertEquals(4, $po->lines[0]->quantity);
        $this->assertSame(8_000, (int) $po->net_total);

        // Open (draft) orders count as on order, so a second click does not duplicate them.
        $again = $this->postJson('/api/v1/stock-control/reorder/convert')->assertCreated();
        $this->assertCount(0, $again->json('orders'));
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_recent_usage_times_lead_time_raises_the_reorder_point(): void
    {
        $s = $this->supplier('S1');
        $rice = $this->item('RICE', 'Rice', 'kg', ['preferred_supplier_id' => $s->id]);
        $this->receive($rice, $this->main, 100, 50);
        $this->putJson("/api/v1/stock-control/lead-times/{$s->id}", ['lead_time_days' => 7])->assertSuccessful();
        $this->par($rice->id, 200, 5);
        $this->getJson('/api/v1/stock-control/reorder/suggestions')->assertOk()->assertJsonCount(0, 'groups');

        // 84 kg issued over the last 28 days = 3 kg a day; 7 days of lead time need 21 kg, and 16 kg is left.
        $this->postJson('/api/v1/stock/issues', ['store_id' => $this->main->id, 'lines' => [['stock_item_id' => $rice->id, 'quantity' => 84]]])->assertCreated();
        $g = $this->getJson('/api/v1/stock-control/reorder/suggestions')->assertOk()->json('groups.0');
        $this->assertSame($s->id, $g['supplier_id']);
        $this->assertEqualsWithDelta(21.0, $g['lines'][0]['reorder_point'], 0.001);
        $this->assertSame(184, $g['lines'][0]['suggested_quantity']); // item default supplier used, 1 kg units
    }

    public function test_par_levels_are_validated_and_permissioned(): void
    {
        $flour = $this->item('FLR', 'Flour', 'kg');
        $this->putJson('/api/v1/stock-control/par-levels', ['store_id' => $this->main->id, 'stock_item_id' => $flour->id, 'par_level' => 10, 'min_level' => 20])->assertStatus(422);
        $this->actAs($this->makeUser($this->group, 'accountant', hotels: [$this->hotel]), $this->hotel);
        $this->putJson('/api/v1/stock-control/par-levels', ['store_id' => $this->main->id, 'stock_item_id' => $flour->id, 'par_level' => 10])->assertForbidden();
        $this->postJson('/api/v1/stock-control/reorder/convert')->assertForbidden();
        $this->getJson('/api/v1/stock-control/reorder/suggestions')->assertOk();
    }
}

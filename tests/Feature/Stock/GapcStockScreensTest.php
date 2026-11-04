<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Control\Domain\StockLot;

/** GAPC: stock and purchasing screens (par levels, lead times, controls, units, FEFO, variance review, scan, RFQ detail, recipe history, PO page). */
class GapcStockScreensTest extends OpsTestCase
{
    public function test_stock_pages_follow_their_permissions(): void
    {
        $pages = ['/stock', '/stock/reorder', '/stock/batches', '/stock/variance', '/stock/scan', '/stock/rfq', '/stock/recipes'];
        foreach (['storekeeper' => 200, 'room-attendant' => 403] as $role => $status) {
            $u = $this->makeUser($this->group, $role, allHotels: true);
            foreach ($pages as $page) {
                $this->actingAs($u)->get($page)->assertStatus($status);
            }
        }
    }

    public function test_purchase_order_page_is_reachable_and_guarded(): void
    {
        $rice = $this->item('RIC', 'Riz');
        $po = app(ProcurementService::class)->createOrder(['supplier_id' => $this->supplier('S1')->id, 'store_id' => $this->main->id, 'lines' => [['stock_item_id' => $rice->id, 'quantity' => 10, 'unit_price' => 700]]]);

        foreach (['storekeeper' => 200, 'accountant' => 200, 'fnb-manager' => 200, 'room-attendant' => 403, 'receptionist' => 403] as $role => $status) {
            $res = $this->actingAs($this->makeUser($this->group, $role, allHotels: true))->get("/purchasing/orders/{$po->id}");
            $res->assertStatus($status);
            if ($status === 200) {
                $res->assertInertia(fn ($p) => $p->component('Purchasing/OrderShow')->where('orderId', $po->id));
            }
        }
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->get("/purchasing/orders/{$po->id}")->getStatusCode(), [302, 401]);

        // The page reads the order and the printable document from the API with the same guard.
        $this->as('storekeeper');
        $this->getJson("/api/v1/purchasing/orders/{$po->id}")->assertOk()->assertJsonPath('number', $po->number)->assertJsonCount(1, 'lines');
        $this->as('room-attendant');
        $this->getJson("/api/v1/purchasing/orders/{$po->id}")->assertForbidden();
        $this->get("/api/v1/documents/purchase-orders/{$po->id}")->assertForbidden();
    }

    public function test_par_levels_lead_times_item_controls_and_units(): void
    {
        $flour = $this->item('FLR', 'Farine');
        $sup = $this->supplier('S1');
        $this->as('storekeeper');

        $par = $this->putJson('/api/v1/stock-control/par-levels', ['store_id' => $this->main->id, 'stock_item_id' => $flour->id, 'par_level' => 50, 'min_level' => 10, 'supplier_id' => $sup->id, 'lead_time_days' => 3])->assertCreated()->json();
        $this->putJson('/api/v1/stock-control/par-levels', ['store_id' => $this->main->id, 'stock_item_id' => $flour->id, 'par_level' => 60])->assertCreated();
        $this->getJson('/api/v1/stock-control/par-levels?store_id='.$this->main->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.stock_item_id', $flour->id);
        $this->assertEquals(60, $this->getJson('/api/v1/stock-control/par-levels')->json('0.par_level'));

        $this->putJson("/api/v1/stock-control/lead-times/{$sup->id}", ['lead_time_days' => 4])->assertSuccessful();
        $this->getJson('/api/v1/stock-control/lead-times')->assertOk()->assertJsonPath('0.lead_time_days', 4);

        $this->putJson("/api/v1/stock-control/item-controls/{$flour->id}", ['tracks_lots' => true, 'shelf_life_days' => 30, 'expiry_alert_days' => 5])->assertSuccessful();
        $this->getJson("/api/v1/stock-control/item-controls/{$flour->id}")->assertOk()->assertJsonPath('shelf_life_days', 30)->assertJsonPath('expiry_alert_days', 5);

        $this->postJson("/api/v1/stock-control/units/{$flour->id}", ['unit' => 'g', 'factor' => 0.001])->assertCreated();
        $this->getJson("/api/v1/stock-control/units/{$flour->id}")->assertOk()->assertJsonFragment(['unit' => 'g']);
        $this->getJson("/api/v1/stock-control/units/{$flour->id}/convert?qty=500&from=g&to=kg")->assertOk()->assertJsonPath('result', 0.5);

        $this->deleteJson("/api/v1/stock-control/par-levels/{$par['id']}")->assertNoContent();

        // The same endpoints refuse a role without stock control.
        $this->as('room-attendant');
        foreach (['par-levels', 'lead-times', "item-controls/{$flour->id}", "units/{$flour->id}"] as $path) {
            $this->getJson("/api/v1/stock-control/$path")->assertForbidden();
        }
        $this->putJson('/api/v1/stock-control/par-levels', ['store_id' => $this->main->id, 'stock_item_id' => $flour->id, 'par_level' => 5])->assertForbidden();
        $this->putJson("/api/v1/stock-control/lead-times/{$sup->id}", ['lead_time_days' => 1])->assertForbidden();
    }

    public function test_fefo_order_and_wastage_reasons(): void
    {
        $yog = $this->item('YOG', 'Yaourt', 'pc');
        $this->putJson("/api/v1/stock-control/item-controls/{$yog->id}", ['tracks_lots' => true])->assertSuccessful();
        foreach ([['L-LATE', 20], ['L-SOON', 3]] as [$no, $days]) {
            $this->postJson('/api/v1/stock-control/lots', ['stock_item_id' => $yog->id, 'store_id' => $this->main->id, 'quantity' => 5, 'unit_cost' => 300, 'lot_number' => $no, 'expiry_date' => now()->addDays($days)->toDateString()])->assertCreated();
        }
        $this->assertSame(2, StockLot::where('stock_item_id', $yog->id)->count());

        $this->as('storekeeper');
        $rows = $this->getJson("/api/v1/stock-control/lots/fefo?stock_item_id={$yog->id}&store_id={$this->main->id}")->assertOk()->json();
        $this->assertSame(['L-SOON', 'L-LATE'], array_column($rows, 'lot_number'));
        $this->getJson('/api/v1/stock/wastage-reasons')->assertOk()->assertJsonFragment(['expired']);

        $this->as('room-attendant');
        $this->getJson("/api/v1/stock-control/lots/fefo?stock_item_id={$yog->id}&store_id={$this->main->id}")->assertForbidden();
    }

    public function test_variance_review_detail_annotate_and_finalize_are_guarded(): void
    {
        $beef = $this->item('BEF', 'Boeuf');
        $this->receive($beef, $this->kitchen, 10, 4_000);
        $this->as('storekeeper');
        $today = now()->toDateString();
        $review = $this->postJson('/api/v1/stock-control/variance/reviews', ['outlet_id' => $this->restaurant->id, 'from' => $today, 'to' => $today])->assertCreated()->json();

        $this->getJson('/api/v1/stock-control/variance/reviews?outlet_id='.$this->restaurant->id)->assertOk()->assertJsonCount(1);
        $this->getJson("/api/v1/stock-control/variance/reviews/{$review['id']}")->assertOk()->assertJsonPath('status', 'draft')->assertJsonStructure(['lines']);

        $this->as('accountant'); // can view, cannot manage
        $this->getJson("/api/v1/stock-control/variance/reviews/{$review['id']}")->assertOk();
        $this->postJson("/api/v1/stock-control/variance/reviews/{$review['id']}/finalize")->assertForbidden();

        $this->as('storekeeper');
        $this->postJson("/api/v1/stock-control/variance/reviews/{$review['id']}/finalize")->assertOk()->assertJsonPath('status', 'final');

        $this->as('room-attendant');
        $this->getJson("/api/v1/stock-control/variance/reviews/{$review['id']}")->assertForbidden();
    }

    public function test_scan_register_receive_and_codes(): void
    {
        $rice = $this->item('RIC', 'Riz');
        $po = app(ProcurementService::class)->createOrder(['supplier_id' => $this->supplier('S1')->id, 'store_id' => $this->main->id, 'lines' => [['stock_item_id' => $rice->id, 'quantity' => 10, 'unit_price' => 700]]]);
        $po->update(['status' => 'approved']);

        $this->as('storekeeper');
        $this->postJson('/api/v1/stock-ops/scan/barcodes', ['stock_item_id' => $rice->id, 'code' => '6009990001'])->assertCreated();
        $this->postJson('/api/v1/stock-ops/scan/receive', ['po' => $po->number, 'code' => '6009990001', 'quantity' => 4, 'delivery_note' => 'BL-1'])->assertCreated();
        $this->assertSame(4.0, $this->onHand($this->main, $rice));
        $this->getJson("/api/v1/stock-ops/scan/codes/item/{$rice->id}")->assertOk()->assertJsonPath('value', 'ITM:RIC')->assertJsonPath('external.0', '6009990001');

        $this->as('room-attendant');
        $this->postJson('/api/v1/stock-ops/scan/barcodes', ['stock_item_id' => $rice->id, 'code' => '6009990002'])->assertForbidden();
        $this->postJson('/api/v1/stock-ops/scan/receive', ['po' => $po->number, 'code' => '6009990001', 'quantity' => 1])->assertForbidden();
        $this->getJson("/api/v1/stock-ops/scan/codes/item/{$rice->id}")->assertForbidden();
    }

    public function test_rfq_detail_and_supplier_sheet(): void
    {
        $flour = $this->item('FLR', 'Farine');
        $a = $this->supplier('SA');
        $this->as('storekeeper');
        $rfq = $this->postJson('/api/v1/stock-ops/rfqs', ['title' => 'Economat', 'store_id' => $this->main->id, 'supplier_ids' => [$a->id], 'lines' => [['stock_item_id' => $flour->id, 'quantity' => 100]]])->assertCreated()->json();

        $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}")->assertOk()->assertJsonPath('lines.0.item_code', 'FLR')->assertJsonPath('suppliers.0.name', 'SA');
        $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/supplier-sheet/{$a->id}")->assertOk()->assertJsonPath('supplier.code', 'SA')->assertJsonPath('lines.0.item_code', 'FLR');

        $this->as('room-attendant');
        $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}")->assertForbidden();
        $this->getJson("/api/v1/stock-ops/rfqs/{$rfq['id']}/supplier-sheet/{$a->id}")->assertForbidden();
    }

    public function test_recipe_history_lists_cost_changes(): void
    {
        $beef = $this->item('BEF', 'Boeuf');
        $this->receive($beef, $this->kitchen, 10, 4_000);
        $menu = $this->dish('STEAK', 5_000);
        $this->as('storekeeper');
        $recipe = $this->postJson('/api/v1/stock-control/recipes', ['code' => 'STK', 'name' => 'Steak', 'pos_menu_item_id' => $menu->id, 'lines' => [['stock_item_id' => $beef->id, 'quantity' => 0.25, 'unit' => 'kg']]])->assertCreated()->json();
        $this->postJson("/api/v1/stock-control/recipes/{$recipe['id']}/recost")->assertSuccessful();

        $this->getJson("/api/v1/stock-control/recipes/{$recipe['id']}/history")->assertOk()->assertJsonStructure([['id', 'date', 'plate_cost', 'net_price', 'margin_pct', 'cause']]);

        $this->as('room-attendant');
        $this->getJson("/api/v1/stock-control/recipes/{$recipe['id']}/history")->assertForbidden();
    }
}

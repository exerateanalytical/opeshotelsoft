<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Control\Domain\StockLot;

class OpsScanTest extends OpsTestCase
{
    public function test_code_values_and_resolution_for_item_lot_and_po(): void
    {
        $item = $this->item('YOG', 'Yaourt', 'pc');
        $this->putJson("/api/v1/stock-control/item-controls/{$item->id}", ['tracks_lots' => true, 'shelf_life_days' => 14])->assertSuccessful();
        $this->receive($item, $this->main, 20, 300, ['date' => now()->toDateString()]);
        $lot = StockLot::where('stock_item_id', $item->id)->firstOrFail();

        $this->getJson("/api/v1/stock-ops/scan/codes/item/{$item->id}")->assertOk()->assertJsonPath('value', 'ITM:YOG');
        $this->getJson("/api/v1/stock-ops/scan/codes/lot/{$lot->id}")->assertOk()->assertJsonPath('value', "LOT:{$lot->id}");

        $this->getJson('/api/v1/stock-ops/scan/resolve?code=ITM:YOG')->assertOk()->assertJsonPath('type', 'item')->assertJsonPath('item.id', $item->id);
        $this->getJson("/api/v1/stock-ops/scan/resolve?code=LOT:{$lot->id}")->assertOk()->assertJsonPath('type', 'lot')->assertJsonPath('item.code', 'YOG');
        $this->getJson('/api/v1/stock-ops/scan/resolve?code=YOG')->assertOk()->assertJsonPath('type', 'item'); // manual entry of the item code
        $this->getJson('/api/v1/stock-ops/scan/resolve?code=ITM:NOPE')->assertStatus(404);

        $po = app(ProcurementService::class)->createOrder(['supplier_id' => $this->supplier('S1')->id, 'store_id' => $this->main->id, 'lines' => [['stock_item_id' => $item->id, 'quantity' => 5, 'unit_price' => 300]]]);
        $this->getJson("/api/v1/stock-ops/scan/resolve?code=PO:{$po->number}")->assertOk()->assertJsonPath('type', 'po')->assertJsonPath('po.id', $po->id);
    }

    public function test_external_barcode_is_registered_once_per_item(): void
    {
        $a = $this->item('A1', 'Eau 1.5l', 'btl');
        $b = $this->item('B1', 'Eau 0.5l', 'btl');
        $this->postJson('/api/v1/stock-ops/scan/barcodes', ['stock_item_id' => $a->id, 'code' => '6001234567890'])->assertCreated();
        $this->postJson('/api/v1/stock-ops/scan/barcodes', ['stock_item_id' => $b->id, 'code' => '6001234567890'])->assertStatus(422);
        $this->postJson('/api/v1/stock-ops/scan/barcodes', ['stock_item_id' => $b->id, 'code' => 'ITM:ZZ'])->assertStatus(422);
        $this->getJson('/api/v1/stock-ops/scan/resolve?code=6001234567890')->assertOk()->assertJsonPath('item.code', 'A1');
        $this->getJson("/api/v1/stock-ops/scan/codes/item/{$a->id}")->assertJsonPath('external.0', '6001234567890');
    }

    public function test_scan_receives_against_a_purchase_order_and_refuses_unexpected_items(): void
    {
        $rice = $this->item('RIC', 'Riz');
        $this->item('SLT', 'Sel');
        $po = app(ProcurementService::class)->createOrder(['supplier_id' => $this->supplier('S1')->id, 'store_id' => $this->main->id, 'lines' => [['stock_item_id' => $rice->id, 'quantity' => 10, 'unit_price' => 700]]]);
        $po->update(['status' => 'approved']);

        $this->postJson('/api/v1/stock-ops/scan/receive', ['po' => "PO:{$po->number}", 'code' => 'ITM:SLT', 'quantity' => 1])->assertStatus(422);
        $this->postJson('/api/v1/stock-ops/scan/receive', ['po' => "PO:{$po->number}", 'code' => 'ITM:RIC', 'quantity' => 4])->assertCreated();
        $this->assertSame(4.0, $this->onHand($this->main, $rice));
        $this->postJson('/api/v1/stock-ops/scan/receive', ['po' => $po->number, 'code' => 'ITM:RIC', 'quantity' => 7])->assertStatus(422); // only 6 left
        $this->postJson('/api/v1/stock-ops/scan/receive', ['po' => $po->number, 'code' => 'ITM:RIC', 'quantity' => 6])->assertCreated();
        $this->assertSame('received', $po->fresh()->status);
        $this->assertBooksBalance();
    }

    public function test_scan_endpoints_need_the_scan_permission(): void
    {
        $this->as('room-attendant');
        $this->getJson('/api/v1/stock-ops/scan/resolve?code=X')->assertForbidden();
    }
}

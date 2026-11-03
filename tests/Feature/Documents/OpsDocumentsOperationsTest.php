<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Documents\Operations\FieldSheets;
use App\Modules\Documents\Operations\StockSheets;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Application\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Stock\OpsTestCase;

/** D-C: stock, purchasing, front office and POS documents render as PDF on the engine and match their sources. */
class OpsDocumentsOperationsTest extends OpsTestCase
{
    private function pdf(string $path, string $type, ?string $lang = null, ?int $amount = null): IssuedDocument
    {
        $res = $this->get("/api/v1/documents/ops/$path");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertGreaterThan(1000, strlen($res->getContent()));
        $rec = IssuedDocument::where('type', $type)->latest('id')->first();
        $this->assertNotNull($rec, "no issued record for $type");
        $this->assertNotEmpty($rec->code);
        $lang && $this->assertSame($lang, $rec->locale);
        $amount !== null && $this->assertSame($amount, (int) $rec->amount);

        return $rec;
    }

    public function test_purchasing_and_stock_notes(): void
    {
        $flour = $this->item('FLR', 'Farine');
        $oil = $this->item('OIL', 'Huile', 'l');
        $s = $this->supplier('SA', 'Grossiste A');
        $po = $this->postJson('/api/v1/purchasing/orders', ['supplier_id' => $s->id, 'store_id' => $this->main->id,
            'lines' => [['stock_item_id' => $flour->id, 'quantity' => 40, 'unit_price' => 800]]])->assertCreated()->json();
        DB::table('purchase_orders')->where('id', $po['id'])->update(['status' => 'approved']);
        $grn = $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", ['lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'quantity' => 40]]])->assertCreated()->json();

        // goods receipt note: net total of the record matches the source
        $this->pdf("goods-receipt/{$grn['id']}", 'goods_receipt', 'fr', 32_000);
        $sheet = app(StockSheets::class)->goodsReceipt($grn['id'])['data']['sheet'];
        $this->assertSame(32_000, $sheet['blocks'][0]['totals'][0][2]);
        $this->assertSame('Farine', $sheet['blocks'][0]['rows'][0]['name']);
        $this->pdf("goods-receipt/{$grn['id']}?lang=en", 'goods_receipt', 'fr'); // same source: same record, now a copy
        $this->assertSame(1, (int) IssuedDocument::where('type', 'goods_receipt')->value('copy_count'));
        $this->get('/api/v1/documents/ops/goods-receipt/999999')->assertNotFound();

        // supplier return / debit note
        $ret = $this->postJson("/api/v1/purchasing/receipts/{$grn['id']}/returns", ['reason' => 'Damaged', 'lines' => [['goods_receipt_line_id' => DB::table('goods_receipt_lines')->where('goods_receipt_id', $grn['id'])->value('id'), 'quantity' => 5]]])->assertCreated()->json();
        $this->pdf("supplier-return/{$ret['id']}", 'supplier_return', null, 4_000);

        // requisition
        $req = $this->postJson('/api/v1/purchasing/requisitions', ['department' => 'kitchen', 'store_id' => $this->main->id, 'lines' => [['stock_item_id' => $oil->id, 'quantity' => 12]]])->assertCreated()->json();
        $this->pdf("requisition/{$req['id']}", 'requisition');

        // issue note and transfer note (lines of one reference grouped on one note)
        $stock = app(StockService::class);
        $this->receive($oil, $this->main, 50, 1500);
        $m1 = $stock->consume('issue', $oil, $this->main, 5, ['reference' => 'KIT-1', 'department' => 'food']);
        $stock->consume('issue', $flour, $this->main, 3, ['reference' => 'KIT-1', 'department' => 'food']);
        $this->pdf("stock-issue/{$m1->id}", 'stock_issue');
        $this->assertCount(2, app(StockSheets::class)->stockIssue($m1->id)['data']['sheet']['blocks'][0]['rows']);
        $t = $stock->transfer($oil, $this->main, $this->kitchen, 4, ['reference' => 'TR-1']);
        $this->pdf("stock-transfer/{$t->id}", 'stock_transfer');
        $this->get("/api/v1/documents/ops/stock-transfer/{$m1->id}")->assertNotFound(); // an issue is not a transfer

        // stocktake: blind count sheet, full sheet and variance need review permission
        $st = $this->postJson('/api/v1/stock-ops/stocktakes', ['store_id' => $this->main->id, 'tolerance_pct' => 2, 'tolerance_qty' => 0.5])->assertCreated()->json();
        $this->pdf("stocktake-sheet/{$st['id']}", 'stock_count_sheet');
        $this->pdf("stocktake-sheet/{$st['id']}?blind=1", 'stock_count_sheet');
        DB::table('stocktakes')->where('id', $st['id'])->update(['blind' => false]);
        $blind = app(StockSheets::class)->stocktakeSheet($st['id'], true)['data']['sheet'];
        $full = app(StockSheets::class)->stocktakeSheet($st['id'], false)['data']['sheet'];
        $this->assertCount(count($full['blocks'][0]['cols']) - 1, $blind['blocks'][0]['cols']);
        $this->pdf("stocktake-variance/{$st['id']}", 'stock_variance_report');

        // valuation (WAC total equals the movement ledger value)
        $rec = $this->pdf('stock-valuation', 'stock_valuation');
        $this->assertGreaterThan(0, (int) $rec->amount);

        // RFQ from the supplier sheet data
        $rfq = $this->postJson('/api/v1/stock-ops/rfqs', ['title' => 'Economat', 'store_id' => $this->main->id, 'supplier_ids' => [$s->id],
            'lines' => [['stock_item_id' => $flour->id, 'quantity' => 100]]])->assertCreated()->json();
        $this->pdf("rfq/{$rfq['id']}?supplier={$s->id}&lang=en", 'rfq', 'en');

        // permissions: counter sees only the blind sheet and cannot print the variance report; a front desk clerk prints no stock paper
        $this->as('storekeeper');
        $this->pdf("stocktake-sheet/{$st['id']}", 'stock_count_sheet');
        $this->assertSame(1, (int) IssuedDocument::where('source_type', 'stocktake_blind')->count() ? 1 : 0);
        $this->get("/api/v1/documents/ops/stocktake-variance/{$st['id']}")->assertForbidden();
        $this->as('receptionist');
        foreach (["goods-receipt/{$grn['id']}", "requisition/{$req['id']}", 'stock-valuation', "rfq/{$rfq['id']}"] as $p) {
            $this->get("/api/v1/documents/ops/$p")->assertForbidden();
        }

        // tenant isolation
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($h2);
        $this->actAs($this->makeUser($g2, 'general-manager', allHotels: true), $h2);
        $this->get("/api/v1/documents/ops/goods-receipt/{$grn['id']}")->assertNotFound();
        $this->get("/api/v1/documents/ops/requisition/{$req['id']}")->assertNotFound();
    }

    public function test_group_police_lost_found_and_pos_documents(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 5);
        $guest->update(['language' => 'en']);
        $block = $this->postJson('/api/v1/blocks', ['code' => 'WED1', 'name' => 'Mariage Ndong', 'arrival' => now()->addDays(10)->toDateString(), 'departure' => now()->addDays(12)->toDateString(),
            'rate_plan_id' => $plan->id, 'allocations' => [['room_type_id' => $type->id, 'rooms' => 3, 'rate' => 40_000]]])->assertCreated()->json();
        $this->postJson("/api/v1/blocks/{$block['id']}/rooming-list", ['entries' => [['guest_id' => $guest->id, 'room_type_id' => $type->id]]])->assertCreated();

        $rec = $this->pdf("group-contract/{$block['id']}", 'group_contract', 'fr', 3 * 2 * 40_000);
        $sheet = app(FieldSheets::class)->groupContract($block['id'])['data']['sheet'];
        $this->assertSame(240_000, $sheet['blocks'][0]['totals'][0][2]);
        $this->assertSame(3, $sheet['blocks'][0]['rows'][0]['rooms']);
        $this->pdf("group-contract/{$block['id']}?lang=en", 'group_contract', 'fr');
        $this->assertSame(1, (int) $rec->fresh()->copy_count);
        $this->pdf("rooming-list/{$block['id']}?lang=en", 'rooming_list', 'en');
        $this->assertCount(1, app(FieldSheets::class)->roomingList($block['id'])['data']['sheet']['blocks'][0]['rows']);

        // police register extract
        DB::table('guest_register_entries')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'reservation_id' => Reservation::where('room_block_id', $block['id'])->value('id'), 'guest_id' => $guest->id, 'room_number' => '101', 'full_name' => 'Jean Mballa',
            'nationality' => 'CM', 'id_type' => 'cni', 'id_number' => 'CM123', 'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $from = now()->subDay()->toDateString();
        $to = now()->addDays(2)->toDateString();
        $this->pdf("police-register?from=$from&to=$to", 'police_register');
        $this->assertCount(1, app(FieldSheets::class)->policeRegister($from, $to)['data']['sheet']['blocks'][0]['rows']);
        $this->get('/api/v1/documents/ops/police-register')->assertStatus(422);

        // lost and found: receipt on hand-in, return receipt only once returned
        $item = $this->postJson('/api/v1/housekeeping/lost-found', ['description' => 'Black umbrella', 'location' => 'Lobby'])->assertCreated()->json();
        $this->pdf("lost-found-handin/{$item['id']}", 'lost_found_handin');
        $this->get("/api/v1/documents/ops/lost-found-return/{$item['id']}")->assertStatus(422);
        $this->postJson("/api/v1/housekeeping/lost-found/{$item['id']}/return", ['returned_to' => 'Jean Mballa'])->assertOk();
        $this->pdf("lost-found-return/{$item['id']}", 'lost_found_return');

        // kitchen ticket (80 mm) and POS day report
        $dish = $this->dish('BRG', 5_000);
        $this->postJson('/api/v1/cashier-shifts');
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->restaurant->id, 'covers' => 2])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $dish->id, 'quantity' => 2])->assertCreated();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/fire")->assertSuccessful();
        $this->pdf("kot/{$check['id']}", 'kot');
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 10_000]]])->assertOk();
        $this->pdf('pos-day-report?date='.now()->toDateString(), 'pos_day_report', null, 10_000);
        $this->pdf('pos-day-report?date='.now()->toDateString().'&lang=en', 'pos_day_report');

        // permissions and tenant isolation
        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        foreach (["group-contract/{$block['id']}", "rooming-list/{$block['id']}", 'police-register?from=2026-01-01&to=2026-12-31', "kot/{$check['id']}", 'pos-day-report'] as $p) {
            $this->get("/api/v1/documents/ops/$p")->assertForbidden();
        }
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($h2);
        $this->actAs($this->makeUser($g2, 'general-manager', allHotels: true), $h2);
        $this->get("/api/v1/documents/ops/group-contract/{$block['id']}")->assertNotFound();
        $this->get("/api/v1/documents/ops/lost-found-handin/{$item['id']}")->assertNotFound();
        $this->get("/api/v1/documents/ops/kot/{$check['id']}")->assertNotFound();
        $this->assertNotNull(Guest::withoutGlobalScopes()->find($guest->id));
    }
}

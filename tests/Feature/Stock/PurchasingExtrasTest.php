<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasingExtrasTest extends TestCase
{
    private Store $store;

    private StockItem $rice;

    private StockItem $beer;

    private Supplier $s1;

    private Supplier $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::create(['code' => 'MAIN', 'name' => 'Magasin', 'kind' => 'main', 'department' => 'fnb']);
        $this->rice = StockItem::create(['code' => 'RIZ', 'name' => 'Riz', 'category' => 'food', 'unit' => 'kg', 'inventory_account' => '311200', 'cost_account' => '603120', 'vat_rate' => 0]);
        $this->beer = StockItem::create(['code' => 'BIERE', 'name' => 'Biere', 'category' => 'beverage', 'unit' => 'btl', 'inventory_account' => '311200', 'cost_account' => '603120', 'vat_rate' => 19.25]);
        $this->s1 = Supplier::create(['code' => 'S1', 'name' => 'Grossiste A']);
        $this->s2 = Supplier::create(['code' => 'S2', 'name' => 'Brasserie B']);
    }

    private function price(Supplier $s, StockItem $i, int $price, array $extra = []): void
    {
        $this->postJson('/api/v1/purchasing/prices', $extra + ['supplier_id' => $s->id, 'stock_item_id' => $i->id, 'unit_price' => $price, 'valid_from' => now()->subDay()->toDateString()])->assertCreated();
    }

    /** Draft → approved order → received GRN, returns the GRN JSON. */
    private function receivedGrn(Supplier $s, StockItem $i, float $qty, int $price): array
    {
        $po = $this->postJson('/api/v1/purchasing/orders', ['supplier_id' => $s->id, 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $i->id, 'quantity' => $qty, 'unit_price' => $price]]])->assertCreated()->json();
        DB::table('purchase_orders')->where('id', $po['id'])->update(['status' => 'approved']);

        return $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", ['lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'quantity' => $qty]]])->assertCreated()->json();
    }

    private function approvedInvoice(Supplier $s, array $grn, string $ref, string $due, int $tax = 0): array
    {
        $inv = $this->postJson('/api/v1/payables/invoices', ['supplier_id' => $s->id, 'reference' => $ref, 'date' => now()->toDateString(), 'due_date' => $due, 'net_total' => $grn['net_total'], 'tax_total' => $tax, 'goods_receipt_ids' => [$grn['id']]])->assertCreated()->json();

        return $this->postJson("/api/v1/payables/invoices/{$inv['id']}/approve")->assertOk()->json();
    }

    public function test_requisitions_need_another_approver_and_convert_into_orders_per_supplier(): void
    {
        $this->price($this->s1, $this->rice, 800);
        $this->price($this->s2, $this->beer, 500);
        $r1 = $this->postJson('/api/v1/purchasing/requisitions', ['department' => 'kitchen', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 10], ['stock_item_id' => $this->beer->id, 'quantity' => 24]]])->assertCreated()->json();
        $r2 = $this->postJson('/api/v1/purchasing/requisitions', ['department' => 'bar', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->beer->id, 'quantity' => 12]]])->assertCreated()->json();

        $this->postJson('/api/v1/purchasing/requisitions/convert', ['requisition_ids' => [$r1['id']]])->assertStatus(422);
        $this->postJson("/api/v1/purchasing/requisitions/{$r1['id']}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson("/api/v1/purchasing/requisitions/{$r1['id']}/approve")->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/sod');
        $this->postJson("/api/v1/purchasing/requisitions/{$r2['id']}/submit")->assertOk();

        $approver = $this->makeUser($this->group, 'general-manager', allHotels: true);
        $this->actAs($approver, $this->hotel);
        $this->postJson("/api/v1/purchasing/requisitions/{$r1['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/purchasing/requisitions/{$r2['id']}/approve")->assertOk();

        $orders = collect($this->postJson('/api/v1/purchasing/requisitions/convert', ['requisition_ids' => [$r1['id'], $r2['id']]])->assertCreated()->json('orders'))->keyBy('supplier_id');
        $this->assertCount(2, $orders);
        $this->assertSame(8000, $orders[$this->s1->id]['net_total']);
        $this->assertEquals(36, $orders[$this->s2->id]['lines'][0]['quantity']);
        $this->assertSame(500, $orders[$this->s2->id]['lines'][0]['unit_price']);
        $this->getJson('/api/v1/purchasing/requisitions?status=converted')->assertJsonPath('total', 2);

        // A storekeeper cannot approve.
        $this->actAs($this->makeUser($this->group, 'storekeeper', allHotels: true), $this->hotel);
        $r3 = $this->postJson('/api/v1/purchasing/requisitions', ['department' => 'bar', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->beer->id, 'quantity' => 1]]])->assertCreated()->json();
        $this->postJson("/api/v1/purchasing/requisitions/{$r3['id']}/submit")->assertOk();
        $this->postJson("/api/v1/purchasing/requisitions/{$r3['id']}/approve")->assertForbidden();
    }

    public function test_orders_prefill_from_active_price_list_and_warn_on_deviation(): void
    {
        $this->price($this->s1, $this->rice, 1000);
        $this->price($this->s1, $this->rice, 900, ['min_quantity' => 50]);
        $this->price($this->s1, $this->rice, 100, ['valid_from' => now()->subYear()->toDateString(), 'valid_to' => now()->subMonth()->toDateString()]);

        $this->getJson("/api/v1/purchasing/prices/lookup?supplier_id={$this->s1->id}&stock_item_id={$this->rice->id}&quantity=60")->assertOk()->assertJsonPath('price.unit_price', 900);

        $po = $this->postJson('/api/v1/purchasing/orders', ['supplier_id' => $this->s1->id, 'store_id' => $this->store->id, 'lines' => [
            ['stock_item_id' => $this->rice->id, 'quantity' => 10],
            ['stock_item_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 1040],
            ['stock_item_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 1100],
        ]])->assertCreated()->json();
        $this->assertSame(1000, $po['lines'][0]['unit_price']);
        $this->assertCount(1, $po['price_warnings']);
        $this->assertSame(2, $po['price_warnings'][0]['line']);
        $this->assertEquals(10.0, $po['price_warnings'][0]['difference_pct']);

        $this->postJson('/api/v1/purchasing/orders', ['supplier_id' => $this->s2->id, 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/no-price');
    }

    public function test_return_to_supplier_reduces_stock_at_grn_cost_and_credit_note_offsets_payables(): void
    {
        $grn = $this->receivedGrn($this->s2, $this->beer, 100, 500);
        $this->approvedInvoice($this->s2, $grn, 'F-1', now()->addDays(30)->toDateString(), 9625);
        // Average cost moves after another receipt; the return must still use the GRN cost.
        $this->receivedGrn($this->s2, $this->beer, 100, 700);

        $ret = $this->postJson("/api/v1/purchasing/receipts/{$grn['id']}/returns", ['reason' => 'Casse livraison', 'lines' => [['goods_receipt_line_id' => $grn['lines'][0]['id'], 'quantity' => 10]]])
            ->assertCreated()->assertJsonPath('net_total', 5000)->assertJsonPath('tax_total', 963)->assertJsonPath('credit_status', 'open')->json();
        $this->assertEquals(190, DB::table('stock_balances')->where('stock_item_id', $this->beer->id)->value('quantity'));
        $this->assertSame(5000, (int) DB::table('stock_movements')->where('kind', 'return')->value('value'));
        $this->postJson("/api/v1/purchasing/receipts/{$grn['id']}/returns", ['lines' => [['goods_receipt_line_id' => $grn['lines'][0]['id'], 'quantity' => 91]]])->assertStatus(422);

        $b = $this->assertBooksBalance();
        // Supplier payable 401: invoice 59625 less credit note 5963.
        $this->assertSame(-(59625 - 5963), $b['401100']);
        $this->assertSame(9625 - 963, $b['445200']);

        $this->getJson('/api/v1/payables/ageing')->assertOk()->assertJsonPath('rows.0.credits', -5963);
        $inv = $this->getJson('/api/v1/payables/invoices')->json('data.0');
        $this->postJson("/api/v1/payables/credit-notes/{$ret['id']}/apply", ['supplier_invoice_id' => $inv['id']])->assertOk()->assertJsonPath('paid', 5963);
        $this->getJson('/api/v1/purchasing/returns')->assertJsonPath('data.0.credit_status', 'applied');
        $this->assertBooksBalance();
    }

    public function test_return_before_invoicing_reduces_the_receipt(): void
    {
        $grn = $this->receivedGrn($this->s1, $this->rice, 20, 1000);
        $this->postJson("/api/v1/purchasing/receipts/{$grn['id']}/returns", ['lines' => [['goods_receipt_line_id' => $grn['lines'][0]['id'], 'quantity' => 5]]])
            ->assertCreated()->assertJsonPath('credit_status', 'receipt');
        $this->assertSame(15000, (int) DB::table('goods_receipts')->where('id', $grn['id'])->value('net_total'));
        $grn['net_total'] = 15000;
        $this->approvedInvoice($this->s1, $grn, 'F-2', now()->toDateString());
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['408100'] ?? 0);
    }

    public function test_wastage_requires_a_listed_reason_and_is_reported(): void
    {
        $this->receivedGrn($this->s1, $this->rice, 20, 1000);
        $this->getJson('/api/v1/stock/wastage-reasons')->assertOk()->assertJsonFragment(['breakage']);
        $this->postJson('/api/v1/stock/issues', ['kind' => 'wastage', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/stock/issues', ['kind' => 'wastage', 'reason' => 'whatever', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/stock/issues', ['kind' => 'wastage', 'reason' => 'expired', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 2]]])->assertCreated();
        $this->postJson('/api/v1/stock/issues', ['kind' => 'wastage', 'reason' => 'breakage', 'store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 1]]])->assertCreated();
        $this->postJson('/api/v1/stock/issues', ['store_id' => $this->store->id, 'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 3]]])->assertCreated();

        $d = now()->toDateString();
        $r = $this->getJson("/api/v1/stock/reports/wastage?from=$d&to=$d")->assertOk()->json();
        $this->assertSame(3000, $r['total']);
        $this->assertSame(2000, collect($r['by_reason'])->firstWhere('reason', 'expired')['value']);
        $this->assertBooksBalance();
    }

    public function test_payment_run_pays_selected_due_invoices_and_ageing_buckets(): void
    {
        $g1 = $this->receivedGrn($this->s1, $this->rice, 10, 1000);
        $g2 = $this->receivedGrn($this->s1, $this->rice, 20, 1000);
        $g3 = $this->receivedGrn($this->s2, $this->beer, 10, 500);
        $g4 = $this->receivedGrn($this->s2, $this->beer, 4, 500);
        $i1 = $this->approvedInvoice($this->s1, $g1, 'A-1', now()->subDays(45)->toDateString());
        $i2 = $this->approvedInvoice($this->s1, $g2, 'A-2', now()->addDays(10)->toDateString());
        $i3 = $this->approvedInvoice($this->s2, $g3, 'B-1', now()->subDays(100)->toDateString());
        $i4 = $this->approvedInvoice($this->s2, $g4, 'B-2', now()->subDays(10)->toDateString());

        $age = $this->getJson('/api/v1/payables/ageing')->assertOk()->json();
        $this->assertSame(['current' => 20000, 'd1_30' => 2000, 'd31_60' => 10000, 'd61_90' => 0, 'd90_plus' => 5000, 'credits' => 0, 'total' => 37000], $age['totals']);

        $due = $this->getJson('/api/v1/payables/invoices/due?due_by='.now()->toDateString())->assertOk()->json();
        $this->assertEqualsCanonicalizing([$i1['id'], $i3['id'], $i4['id']], array_column($due, 'id'));
        $this->assertCount(1, $this->getJson('/api/v1/payables/invoices/due?due_by='.now()->toDateString().'&supplier_id='.$this->s1->id)->json());

        $this->postJson('/api/v1/payables/payment-runs', ['invoice_ids' => [$i1['id']], 'account' => '601100'])->assertStatus(422);
        $this->postJson('/api/v1/payables/payment-runs', ['invoice_ids' => [$i1['id'], $i3['id'], $i4['id']], 'account' => '521100'])
            ->assertCreated()->assertJsonPath('payments_count', 3)->assertJsonPath('total', 17000);
        $this->assertSame(3, DB::table('supplier_payments')->whereNotNull('payment_run_id')->count());
        $this->postJson('/api/v1/payables/payment-runs', ['invoice_ids' => [$i1['id']], 'account' => '521100'])->assertStatus(422);

        $b = $this->assertBooksBalance();
        $this->assertSame(-20000, $b['401100']);
        $this->assertSame(-17000, $b['521100']);
        $this->getJson('/api/v1/payables/ageing')->assertJsonPath('totals.total', 20000);

        $this->actAs($this->makeUser($this->group, 'storekeeper', allHotels: true), $this->hotel);
        $this->postJson('/api/v1/payables/payment-runs', ['invoice_ids' => [$i2['id']], 'account' => '521100'])->assertForbidden();
        $this->getJson('/api/v1/payables/ageing')->assertForbidden();
    }
}

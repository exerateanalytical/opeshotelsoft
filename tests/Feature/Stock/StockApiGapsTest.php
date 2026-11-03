<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\StockCount;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use Tests\TestCase;

class StockApiGapsTest extends TestCase
{
    public function test_open_counts_can_be_listed_and_resumed_with_item_names(): void
    {
        $store = Store::create(['code' => 'MAIN', 'name' => 'Magasin', 'kind' => 'main']);
        $other = Store::create(['code' => 'BAR', 'name' => 'Bar', 'kind' => 'bar']);
        $item = StockItem::create(['code' => 'SOAP', 'name' => 'Savon', 'category' => 'amenity', 'unit' => 'pc', 'inventory_account' => '311200', 'cost_account' => '603120']);
        \DB::table('stock_balances')->insert(['hotel_id' => $this->hotel->id, 'store_id' => $store->id, 'stock_item_id' => $item->id, 'quantity' => 5]);

        $c = $this->postJson('/api/v1/stock/counts', ['store_id' => $store->id])->assertCreated()->json();
        $this->postJson('/api/v1/stock/counts', ['store_id' => $other->id])->assertCreated();
        StockCount::create(['store_id' => $store->id, 'date' => now()->toDateString(), 'status' => 'posted']);

        $this->getJson('/api/v1/stock/counts?status=open&store_id='.$store->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $c['id'])->assertJsonPath('data.0.lines_count', 1);
        $this->getJson('/api/v1/stock/counts?status=open')->assertOk()->assertJsonPath('total', 2);

        $this->putJson("/api/v1/stock/counts/{$c['id']}", ['lines' => [['stock_item_id' => $item->id, 'counted' => 4]]])->assertOk();
        $this->getJson("/api/v1/stock/counts/{$c['id']}")->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('lines.0.item_name', 'Savon')->assertJsonPath('lines.0.item_code', 'SOAP')
            ->assertJsonPath('lines.0.counted', 4);
    }

    public function test_cancelling_a_received_order_returns_problem_details(): void
    {
        $supplier = Supplier::create(['code' => 'S1', 'name' => 'Fournisseur']);
        $store = Store::create(['code' => 'MAIN', 'name' => 'Magasin', 'kind' => 'main']);
        $po = PurchaseOrder::create(['number' => 'BC-1', 'supplier_id' => $supplier->id, 'store_id' => $store->id, 'order_date' => now()->toDateString(), 'status' => 'received']);

        $this->postJson("/api/v1/purchasing/orders/{$po->id}/cancel")->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/invalid-state')
            ->assertJsonPath('detail', 'Purchase order BC-1 is received; only draft, submitted or approved orders with nothing received can be cancelled.');

        $po->update(['status' => 'cancelled']);
        $this->postJson("/api/v1/purchasing/orders/{$po->id}/cancel")->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/invalid-state');

        $po->update(['status' => 'draft']);
        $this->postJson("/api/v1/purchasing/orders/{$po->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_payment_accounts_are_the_hotels_treasury_accounts(): void
    {
        $codes = collect($this->getJson('/api/v1/payables/payment-accounts')->assertOk()->json())->pluck('name', 'code');

        $this->assertSame('Banque', $codes['521100'] ?? null);
        $this->assertArrayHasKey('571100', $codes->all());
        $this->assertArrayNotHasKey('585100', $codes->all(), 'internal transfer account is not a payment source');
        $this->assertTrue($codes->keys()->every(fn ($c) => str_starts_with($c, '5')));
    }
}

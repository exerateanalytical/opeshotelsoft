<?php

namespace Tests\Feature\Stock;

use App\Modules\POS\Domain\ItemComponent;

class OpsMenuEngineeringTest extends OpsTestCase
{
    /** @return array<string, array> menu item by key */
    private function sales(): array
    {
        $out = [];
        // key => [price incl. VAT, ingredient unit cost, quantity sold, has components]
        foreach (['A' => [5962, 1000, 40, true], 'B' => [5962, 3500, 30, true], 'C' => [11925, 2000, 3, true], 'D' => [5962, 3500, 2, true], 'E' => [5962, 0, 1, false]] as $key => [$price, $cost, $qty, $comp]) {
            $menu = $this->dish("D$key", $price);
            if ($comp) {
                $ing = $this->item("ING$key", "Ingredient $key", 'pc');
                $this->receive($ing, $this->kitchen, 1000, $cost);
                ItemComponent::create(['pos_menu_item_id' => $menu->id, 'stock_item_id' => $ing->id, 'quantity' => 1]);
            }
            $this->sell($menu, $qty, $price * $qty);
            $out[$key] = $menu;
        }

        return $out;
    }

    public function test_quadrants_cost_percent_and_suggestions_from_pos_sales(): void
    {
        $m = $this->sales();
        $res = $this->getJson('/api/v1/stock-ops/menu-engineering')->assertOk()->json();
        $items = collect($res['items'])->keyBy('menu_item_id');

        $this->assertSame(5, $res['summary']['items']);
        $this->assertSame(76.0, (float) $res['summary']['quantity']);
        $this->assertSame('star', $items[$m['A']->id]['quadrant']);
        $this->assertSame('plowhorse', $items[$m['B']->id]['quadrant']);
        $this->assertSame('puzzle', $items[$m['C']->id]['quadrant']);
        $this->assertSame('dog', $items[$m['D']->id]['quadrant']);

        $a = $items[$m['A']->id];
        $this->assertEqualsWithDelta(20.0, $a['cost_pct'], 0.1);
        $this->assertEqualsWithDelta(4000, $a['unit_margin'], 1.0);
        $this->assertSame(40.0, (float) $a['quantity']);
        $this->assertSame('keep_promote', $a['suggestions'][0]['code']);
        $this->assertSame('raise_price_or_cut_cost', $items[$m['B']->id]['suggestions'][0]['code']);
        $this->assertContains('cost_above_target', array_column($items[$m['B']->id]['suggestions'], 'code')); // 70% cost against 35% target (65% margin)
        $this->assertContains('missing_cost', array_column($items[$m['E']->id]['suggestions'], 'code'));
        $this->assertFalse($items[$m['E']->id]['cost_known']);
    }

    public function test_period_and_outlet_filters_and_empty_report(): void
    {
        $this->sales();
        $this->getJson('/api/v1/stock-ops/menu-engineering?from='.now()->addDays(5)->toDateString().'&to='.now()->addDays(9)->toDateString())->assertOk()->assertJsonPath('summary.items', 0);
        $this->getJson('/api/v1/stock-ops/menu-engineering?outlet_id='.$this->restaurant->id)->assertOk()->assertJsonPath('summary.items', 5);
        $this->getJson('/api/v1/stock-ops/menu-engineering?outlet_id=999999')->assertOk()->assertJsonPath('summary.items', 0);
    }

    public function test_recipe_cost_is_used_when_the_dish_has_a_recipe(): void
    {
        $menu = $this->dish('RCP', 5962);
        $ing = $this->item('FLR', 'Farine', 'kg');
        $this->receive($ing, $this->kitchen, 100, 800);
        $this->postJson('/api/v1/stock-control/recipes', [
            'code' => 'R1', 'name' => 'Plat', 'pos_menu_item_id' => $menu->id, 'lines' => [['stock_item_id' => $ing->id, 'quantity' => 0.5, 'yield_pct' => 50]],
        ])->assertCreated();
        $this->sell($menu, 2, 2 * 5962);

        $row = $this->getJson('/api/v1/stock-ops/menu-engineering')->assertOk()->json('items.0');
        $this->assertEqualsWithDelta(800.0, $row['unit_cost'], 0.5); // 0.5 kg at 50% yield = 1 kg * 800
        $this->assertTrue($row['cost_known']);
    }
}

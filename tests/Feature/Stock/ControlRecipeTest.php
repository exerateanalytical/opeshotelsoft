<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Control\Domain\Recipe;
use App\Platform\Events\StoredEvent;
use Illuminate\Support\Facades\DB;

class ControlRecipeTest extends ControlTestCase
{
    private function setUpBeefAndSauce(): array
    {
        $beef = $this->item('BEEF', 'Beef fillet', 'kg');
        $tomato = $this->item('TOM', 'Tomato', 'kg');
        $this->receive($beef, $this->kitchen, 20, 4_000);
        $this->receive($tomato, $this->kitchen, 20, 500);
        $this->postJson("/api/v1/stock-control/units/{$beef->id}", ['unit' => 'g', 'factor' => 0.001])->assertCreated();
        $sauce = $this->postJson('/api/v1/stock-control/recipes', [
            'code' => 'SAUCE', 'name' => 'Tomato sauce', 'kind' => 'sub_recipe', 'yield_qty' => 1, 'yield_unit' => 'l',
            'lines' => [['stock_item_id' => $tomato->id, 'quantity' => 2, 'unit' => 'kg']],
        ])->assertCreated()->json();

        return [$beef, $tomato, $sauce];
    }

    public function test_plate_cost_uses_yield_units_and_sub_recipes_and_margin(): void
    {
        [$beef, , $sauce] = $this->setUpBeefAndSauce();
        $menu = $this->dish('STEAK', 5_000);
        $r = $this->postJson('/api/v1/stock-control/recipes', [
            'code' => 'STEAKR', 'name' => 'Steak', 'pos_menu_item_id' => $menu->id, 'target_margin_pct' => 70,
            'lines' => [
                ['stock_item_id' => $beef->id, 'quantity' => 200, 'unit' => 'g', 'yield_pct' => 80],
                ['sub_recipe_id' => $sauce['id'], 'quantity' => 0.1],
            ],
        ])->assertCreated();

        // 200 g / 0.8 = 0.25 kg x 4 000 = 1 000; sauce 0.1 l x 1 000 = 100.
        $r->assertJsonPath('analysis.plate_cost', 1100);
        $this->assertEqualsWithDelta(73.77, $r->json('analysis.margin_pct'), 0.01);
        $r->assertJsonPath('analysis.below_target', false);
        $this->assertSame(1100.0, (float) Recipe::where('code', 'STEAKR')->value('plate_cost'));
    }

    public function test_cost_follows_the_ingredient_and_flags_low_margin(): void
    {
        [$beef] = $this->setUpBeefAndSauce();
        $menu = $this->dish('STEAK', 5_000);
        $this->postJson('/api/v1/stock-control/recipes', [
            'code' => 'STEAKR', 'name' => 'Steak', 'pos_menu_item_id' => $menu->id, 'target_margin_pct' => 70,
            'lines' => [['stock_item_id' => $beef->id, 'quantity' => 250, 'unit' => 'g']],
        ])->assertCreated()->assertJsonPath('analysis.plate_cost', 1000);

        // 20 kg at 4 000 plus 20 kg at 8 000: the average cost goes to 6 000.
        $this->receive($beef, $this->kitchen, 20, 8_000);

        $recipe = Recipe::where('code', 'STEAKR')->first();
        $this->assertSame(1500.0, (float) $recipe->plate_cost);
        $this->assertSame(2, DB::table('recipe_cost_history')->where('recipe_id', $recipe->id)->count());
        $this->assertTrue(StoredEvent::where('type', 'stock.recipe_margin_low')->exists());
        $this->getJson("/api/v1/stock-control/recipes/{$recipe->id}")->assertOk()->assertJsonPath('analysis.below_target', true);
    }

    public function test_pos_sale_consumes_stock_through_the_recipe_and_books_balance(): void
    {
        [$beef, $tomato, $sauce] = $this->setUpBeefAndSauce();
        $menu = $this->dish('STEAK', 5_000);
        $this->postJson('/api/v1/stock-control/recipes', [
            'code' => 'STEAKR', 'name' => 'Steak', 'pos_menu_item_id' => $menu->id,
            'lines' => [['stock_item_id' => $beef->id, 'quantity' => 200, 'unit' => 'g', 'yield_pct' => 80], ['sub_recipe_id' => $sauce['id'], 'quantity' => 0.1]],
        ])->assertCreated();
        // A flat component that must be ignored once a recipe exists.
        $menu->components()->create(['stock_item_id' => $beef->id, 'quantity' => 9]);

        $this->sell($menu, 2, 10_000);

        $this->assertEqualsWithDelta(19.5, $this->onHand($this->kitchen, $beef), 0.0001);   // 2 x 0.25 kg
        $this->assertEqualsWithDelta(19.6, $this->onHand($this->kitchen, $tomato), 0.0001); // 2 x 0.1 l x 2 kg
        $b = $this->assertBooksBalance();
        $this->assertSame(2_200, $b['603110'], 'cost of the two plates');
    }

    public function test_dish_without_recipe_still_uses_flat_components(): void
    {
        $beef = $this->item('BEEF', 'Beef', 'kg');
        $this->receive($beef, $this->kitchen, 10, 4_000);
        $menu = $this->dish('GRILL', 5_000);
        $menu->components()->create(['stock_item_id' => $beef->id, 'quantity' => 0.5]);

        $this->sell($menu, 2, 10_000);

        $this->assertEqualsWithDelta(9.0, $this->onHand($this->kitchen, $beef), 0.0001);
        $this->assertBooksBalance();
    }

    public function test_recipe_rules_cycles_missing_units_duplicates_and_permissions(): void
    {
        $beef = $this->item('BEEF', 'Beef', 'kg');
        $a = $this->postJson('/api/v1/stock-control/recipes', ['code' => 'A', 'name' => 'A', 'kind' => 'sub_recipe', 'lines' => [['stock_item_id' => $beef->id, 'quantity' => 1, 'unit' => 'kg']]])->assertCreated()->json();
        $b = $this->postJson('/api/v1/stock-control/recipes', ['code' => 'B', 'name' => 'B', 'kind' => 'sub_recipe', 'lines' => [['sub_recipe_id' => $a['id'], 'quantity' => 1]]])->assertCreated()->json();
        $this->putJson("/api/v1/stock-control/recipes/{$a['id']}", ['code' => 'A', 'name' => 'A', 'lines' => [['sub_recipe_id' => $b['id'], 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/recipe-cycle');

        $this->postJson('/api/v1/stock-control/recipes', ['code' => 'C', 'name' => 'C', 'lines' => [['stock_item_id' => $beef->id, 'quantity' => 5, 'unit' => 'oz']]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/uom-missing');

        $menu = $this->dish('X', 1_000);
        $line = ['stock_item_id' => $beef->id, 'quantity' => 1, 'unit' => 'kg'];
        $this->postJson('/api/v1/stock-control/recipes', ['code' => 'D1', 'name' => 'D1', 'pos_menu_item_id' => $menu->id, 'lines' => [$line]])->assertCreated();
        $this->postJson('/api/v1/stock-control/recipes', ['code' => 'D2', 'name' => 'D2', 'pos_menu_item_id' => $menu->id, 'lines' => [$line]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/recipe-duplicate');

        $this->actAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/stock-control/recipes')->assertForbidden();
    }

    public function test_unit_conversion_between_purchase_stock_and_recipe_units(): void
    {
        $oil = $this->item('OIL', 'Oil', 'l', ['purchase_unit' => 'case', 'purchase_factor' => 12]);
        $this->postJson("/api/v1/stock-control/units/{$oil->id}", ['unit' => 'cl', 'factor' => 0.01])->assertCreated();

        $this->getJson("/api/v1/stock-control/units/{$oil->id}/convert?qty=2&from=case&to=l")->assertOk()->assertJsonPath('result', 24);
        $this->getJson("/api/v1/stock-control/units/{$oil->id}/convert?qty=250&from=cl&to=l")->assertOk()->assertJsonPath('result', 2.5);
        $this->getJson("/api/v1/stock-control/units/{$oil->id}/convert?qty=1&from=case&to=cl")->assertOk()->assertJsonPath('result', 1200);
        $this->getJson("/api/v1/stock-control/units/{$oil->id}")->assertOk()->assertJsonCount(3);
    }
}

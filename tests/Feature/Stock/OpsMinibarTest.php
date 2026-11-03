<?php

namespace Tests\Feature\Stock;

use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Domain\Store;

class OpsMinibarTest extends OpsTestCase
{
    private Store $minibar;

    private function stay(): array
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();

        $cola = $this->item('COLA', 'Cola', 'btl', ['category' => 'beverage']);
        $nuts = $this->item('NUT', 'Noix', 'pc', ['category' => 'beverage']);
        $this->minibar = Store::create(['code' => 'MB', 'name' => 'Minibars', 'kind' => 'minibar']);
        $this->receive($cola, $this->main, 100, 300);
        $this->receive($nuts, $this->main, 100, 500);
        $this->receive($cola, $this->minibar, 10, 300);
        $this->receive($nuts, $this->minibar, 10, 500);
        $this->putJson('/api/v1/stock-ops/minibar/pars', ['room_type_id' => $type->id, 'stock_item_id' => $cola->id, 'par_qty' => 2, 'price' => 1000])->assertSuccessful();
        $this->putJson('/api/v1/stock-ops/minibar/pars', ['room_type_id' => $type->id, 'stock_item_id' => $nuts->id, 'par_qty' => 1, 'price' => 1500])->assertSuccessful();

        return [$r->fresh(), $room, $cola, $nuts];
    }

    public function test_consumption_charges_the_folio_and_deducts_the_minibar_store_with_balanced_books(): void
    {
        [$r, $room, $cola, $nuts] = $this->stay();
        $before = Folio::where('reservation_id', $r->id)->first()?->balance ?? 0;

        $this->postJson('/api/v1/stock-ops/minibar/consumptions', ['room_id' => $room->id, 'items' => [
            ['stock_item_id' => $cola->id, 'quantity' => 2], ['stock_item_id' => $nuts->id, 'quantity' => 1]]])->assertCreated()->assertJsonCount(2);

        $folio = Folio::where('reservation_id', $r->id)->where('kind', 'guest')->firstOrFail();
        $this->assertSame($before + 2 * 1000 + 1500, $folio->fresh()->balance);
        $this->assertSame(8.0, $this->onHand($this->minibar, $cola));
        $this->assertSame(9.0, $this->onHand($this->minibar, $nuts));
        $this->assertBooksBalance();
    }

    public function test_consumption_needs_a_guest_in_house_and_a_listed_item(): void
    {
        [$r, $room, $cola] = $this->stay();
        $other = $this->item('JUS', 'Jus');
        $this->postJson('/api/v1/stock-ops/minibar/consumptions', ['room_id' => $room->id, 'items' => [['stock_item_id' => $other->id, 'quantity' => 1]]])->assertStatus(422);
        $empty = Room::where('id', '!=', $room->id)->first();
        $this->postJson('/api/v1/stock-ops/minibar/consumptions', ['room_id' => $empty->id, 'items' => [['stock_item_id' => $cola->id, 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(10.0, $this->onHand($this->minibar, $cola));
    }

    public function test_checkout_generates_a_restock_task_and_completing_it_moves_stock_from_the_main_store(): void
    {
        [$r, $room, $cola, $nuts] = $this->stay();
        $this->postJson('/api/v1/stock-ops/minibar/consumptions', ['room_id' => $room->id, 'items' => [['stock_item_id' => $cola->id, 'quantity' => 2]]])->assertCreated();

        $folio = Folio::where('reservation_id', $r->id)->where('kind', 'guest')->firstOrFail();
        if ($folio->fresh()->balance > 0) {
            $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 0])->assertCreated();
            $this->postJson("/api/v1/folios/{$folio->id}/payments", ['method' => 'CASH', 'amount' => $folio->fresh()->balance])->assertCreated();
        }
        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk();

        $tasks = $this->getJson('/api/v1/stock-ops/minibar/tasks?status=pending')->assertOk()->json();
        $this->assertCount(1, $tasks);
        $this->assertSame($room->number, $tasks[0]['room_number']);
        $this->assertCount(1, $tasks[0]['lines']);
        $this->assertSame($cola->id, $tasks[0]['lines'][0]['stock_item_id']);
        $this->assertEquals(2, $tasks[0]['lines'][0]['quantity']);
        $this->assertSame('Cola', $tasks[0]['lines'][0]['item_name']);

        $this->postJson("/api/v1/stock-ops/minibar/tasks/{$tasks[0]['id']}/complete")->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame(98.0, $this->onHand($this->main, $cola));
        $this->assertSame(10.0, $this->onHand($this->minibar, $cola)); // 8 + 2 put back
        $this->postJson("/api/v1/stock-ops/minibar/tasks/{$tasks[0]['id']}/complete")->assertStatus(422);
        $this->assertBooksBalance();
    }

    public function test_no_task_for_room_types_without_minibar_and_permissions_apply(): void
    {
        [$r, $room] = $this->stay();
        Room::where('id', $room->id)->update([]);
        $this->as('receptionist');
        $this->getJson('/api/v1/stock-ops/minibar/tasks')->assertForbidden();
        $this->putJson('/api/v1/stock-ops/minibar/pars', ['room_type_id' => $room->room_type_id, 'stock_item_id' => 1, 'par_qty' => 1, 'price' => 1])->assertForbidden();
        $this->assertSame(0, Reservation::where('status', 'checked_out')->count());
    }
}

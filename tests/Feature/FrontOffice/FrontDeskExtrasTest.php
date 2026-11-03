<?php

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Guests\Domain\Company;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** W07: in-house list, early/late check-out fees, room move, folio routing, registration card, cashier list. */
class FrontDeskExtrasTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkedIn(int $nights = 1, int $rate = 59_625): Reservation
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 3, rate: $rate);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM', 'vip' => true]);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDays($nights)->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->orderBy('number')->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();

        return $r->fresh();
    }

    private function folioOf(Reservation $r): array
    {
        return $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->json();
    }

    public function test_in_house_list_shows_room_balance_vip_company_and_searches(): void
    {
        $r = $this->checkedIn();
        $company = Company::create(['name' => 'SONARA']);
        $r->update(['company_id' => $company->id]);
        $this->postJson('/api/v1/night-audit')->assertOk();

        $rows = $this->getJson('/api/v1/front-desk/in-house')->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame('101', $rows[0]['room']);
        $this->assertSame(60_625, $rows[0]['balance']);
        $this->assertTrue($rows[0]['guest']['vip']);
        $this->assertSame('SONARA', $rows[0]['company']);
        $this->assertSame($r->departure->toDateString(), $rows[0]['departure']);

        $this->assertCount(1, $this->getJson('/api/v1/front-desk/in-house?q=mballa')->json());
        $this->assertCount(1, $this->getJson('/api/v1/front-desk/in-house?q=sonara')->json());
        $this->assertCount(0, $this->getJson('/api/v1/front-desk/in-house?q=nobody')->json());

        $attendant = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($attendant, $this->hotel)->getJson('/api/v1/front-desk/in-house')->assertForbidden();
    }

    public function test_in_house_web_page_needs_front_desk_permission(): void
    {
        $this->withoutVite();
        $this->actingAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]))
            ->get('/front-desk/in-house')->assertOk();
        $this->actingAs($this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]))
            ->get('/front-desk/in-house')->assertForbidden();
    }

    public function test_late_checkout_fee_rule_must_be_posted_or_waived_and_books_balance(): void
    {
        Carbon::setTestNow(now()->setTime(9, 0));
        $this->putJson('/api/v1/front-desk/checkout-fee-rules', ['rules' => [
            ['kind' => 'late', 'after_hour' => 12, 'charge_code' => 'MISC', 'fee_type' => 'percent_of_night', 'value' => 50, 'label' => 'Half-day'],
            ['kind' => 'late', 'after_hour' => 18, 'charge_code' => 'MISC', 'fee_type' => 'percent_of_night', 'value' => 100, 'label' => 'Full day'],
        ]])->assertOk()->assertJsonCount(2);
        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel)->putJson('/api/v1/front-desk/checkout-fee-rules', ['rules' => []])->assertForbidden();
        $this->actAs($this->user, $this->hotel);

        $r = $this->checkedIn();
        $this->postJson('/api/v1/night-audit')->assertOk(); // the guest is now due out today

        $this->assertNull($this->getJson("/api/v1/reservations/{$r->id}/check-out/quote")->json('fee'));
        Carbon::setTestNow(now()->setTime(14, 0));
        $quote = $this->getJson("/api/v1/reservations/{$r->id}/check-out/quote")->assertOk()->json();
        $this->assertSame('late', $quote['type']);
        $this->assertSame(29_813, $quote['fee']['amount']); // half of 59 625

        $folio = $this->folioOf($r);
        $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 0])->assertCreated();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'CASH', 'amount' => 60_625])->assertCreated();

        $this->postJson("/api/v1/reservations/{$r->id}/check-out")
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/checkout-fee-pending');
        $this->actAs($receptionist, $this->hotel)->postJson("/api/v1/reservations/{$r->id}/check-out", ['waive_fee' => true])->assertForbidden();
        $this->actAs($this->user, $this->hotel);

        $this->postJson("/api/v1/reservations/{$r->id}/check-out/fee")->assertCreated()->assertJsonPath('amount', 29_813);
        $this->postJson("/api/v1/reservations/{$r->id}/check-out/fee")->assertStatus(422);
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'CASH', 'amount' => 29_813])->assertCreated();
        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk()->assertJsonPath('status', 'checked_out');

        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['411100'] ?? 0);
    }

    public function test_late_fee_can_be_waived_by_a_manager(): void
    {
        Carbon::setTestNow(now()->setTime(15, 0));
        $this->putJson('/api/v1/front-desk/checkout-fee-rules', ['rules' => [
            ['kind' => 'late', 'after_hour' => 12, 'charge_code' => 'MISC', 'fee_type' => 'fixed', 'value' => 10_000],
        ]])->assertOk();
        $r = $this->checkedIn();
        $this->postJson('/api/v1/night-audit')->assertOk();
        $folio = $this->folioOf($r);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'CASH', 'amount' => 60_625])->assertCreated();

        $this->postJson("/api/v1/reservations/{$r->id}/check-out", ['waive_fee' => true])->assertOk();
    }

    public function test_early_departure_releases_remaining_nights(): void
    {
        $r = $this->checkedIn(nights: 3);
        $this->assertSame(3, $r->nights()->count());
        $this->postJson('/api/v1/night-audit')->assertOk();

        $quote = $this->getJson("/api/v1/reservations/{$r->id}/check-out/quote")->assertOk()->json();
        $this->assertTrue($quote['early']);
        $this->assertSame(1, $quote['nights_released']);

        $folio = $this->folioOf($r);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'CASH', 'amount' => $folio['balance']])->assertCreated();
        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk();

        $r->refresh();
        $this->assertSame(2, $r->nights()->count());
        $this->assertSame(now()->addDays(2)->toDateString(), $r->departure->toDateString());
        $this->assertBooksBalance();
    }

    public function test_room_move_picker_lists_vacant_rooms_and_moves(): void
    {
        $r = $this->checkedIn();
        $rooms = $this->getJson("/api/v1/reservations/{$r->id}/vacant-rooms")->assertOk()->json();
        $this->assertSame(['102', '103'], array_column($rooms, 'number'));

        $this->postJson("/api/v1/reservations/{$r->id}/move-room", ['room_id' => $rooms[0]['id'], 'reason' => 'AC broken'])
            ->assertOk()->assertJsonPath('room.number', '102');
        $this->assertSame(['101', '103'], array_column($this->getJson("/api/v1/reservations/{$r->id}/vacant-rooms")->json(), 'number'));
    }

    public function test_routing_rules_send_room_to_company_and_extras_to_guest_and_books_balance(): void
    {
        $r = $this->checkedIn();
        $company = Company::create(['name' => 'SONARA', 'city_ledger' => true, 'credit_limit' => 1_000_000]);
        $master = $this->postJson('/api/v1/folios', ['kind' => 'company', 'name' => 'SONARA master', 'company_id' => $company->id])->assertCreated()->json();
        $guestFolio = $this->folioOf($r);

        $this->postJson("/api/v1/reservations/{$r->id}/routing-rules", ['match_type' => 'department', 'match_value' => 'rooms', 'target_folio_id' => $guestFolio['id']])
            ->assertStatus(422);
        $rule = $this->postJson("/api/v1/reservations/{$r->id}/routing-rules", ['match_type' => 'department', 'match_value' => 'rooms', 'target_folio_id' => $master['id']])
            ->assertCreated()->json();
        $this->assertSame('SONARA master', $this->getJson("/api/v1/reservations/{$r->id}/routing-rules")->json('0.target.name'));

        // Night audit room charge is routed to the company.
        $this->postJson('/api/v1/night-audit')->assertOk();
        // An extra posted by the front desk stays with the guest.
        $this->postJson("/api/v1/folios/{$guestFolio['id']}/charges", ['charge_code' => 'MINI', 'amount' => 5_000])->assertCreated();
        // A POS room charge goes through the same service (PosService::chargeToRoom -> FolioService::charge).
        app(FolioService::class)->charge(Folio::findOrFail($guestFolio['id']), 'FNB', 12_000, ['source_type' => 'pos_check', 'source_id' => 1]);
        $this->postJson("/api/v1/reservations/{$r->id}/routing-rules", ['match_type' => 'charge_code', 'match_value' => 'BAR', 'target_folio_id' => $master['id']])->assertCreated();
        app(FolioService::class)->charge(Folio::findOrFail($guestFolio['id']), 'BAR', 3_000, ['source_type' => 'pos_check', 'source_id' => 2]);

        $this->assertSame(60_625 + 3_000, Folio::find($master['id'])->balance);
        $this->assertSame(17_000, Folio::find($guestFolio['id'])->balance);

        $this->deleteJson("/api/v1/reservations/{$r->id}/routing-rules/{$rule['id']}")->assertNoContent();
        $this->assertDatabaseMissing('folio_routing_rules', ['id' => $rule['id']]);

        $b = $this->assertBooksBalance();
        $this->assertSame(-50_000, $b['706100'], 'room revenue recognised once');
        $this->assertSame(80_625, $b['411100'] ?? 0, 'all postings land on guest/company folios');
    }

    public function test_registration_card_photo_and_signature_are_stored_privately_and_served(): void
    {
        Storage::fake('local');
        $r = $this->checkedIn();

        $card = $this->postJson("/api/v1/reservations/{$r->id}/registration-card", ['id_photo_data' => self::PNG, 'signature' => self::PNG])
            ->assertOk()->json();
        $this->assertTrue($card['has_id_photo']);
        $this->assertTrue($card['has_signature']);
        $this->assertSame('CM123456', $card['id_number']);
        Storage::disk('local')->assertExists($card['id_photo_path']);
        Storage::disk('local')->assertExists($card['signature_path']);
        $this->assertStringStartsWith("registration-cards/{$this->hotel->id}/{$r->id}/", $card['signature_path']);

        $this->get("/api/v1/reservations/{$r->id}/registration-card/signature")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/api/v1/reservations/{$r->id}/registration-card/id-photo")->assertOk();

        $this->postJson("/api/v1/reservations/{$r->id}/registration-card", ['signature' => 'data:image/png;base64,'.base64_encode('not an image')])
            ->assertStatus(422);

        $attendant = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($attendant, $this->hotel)->get("/api/v1/reservations/{$r->id}/registration-card/id-photo")->assertForbidden();
        $this->postJson("/api/v1/reservations/{$r->id}/registration-card", ['signature' => self::PNG])->assertForbidden();
    }

    public function test_cashier_shift_list_has_user_name_and_filters(): void
    {
        $other = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 1_000])->assertCreated();
        $this->actAs($other, $this->hotel)->postJson('/api/v1/cashier-shifts')->assertCreated();
        $this->actAs($this->user, $this->hotel);
        DB::table('cashier_shifts')->where('user_id', $other->id)->update(['business_date' => now()->subDays(5)->toDateString()]);

        $all = $this->getJson('/api/v1/cashier-shifts')->assertOk()->json('data');
        $this->assertCount(2, $all);
        $this->assertContains($other->name, array_column($all, 'user_name'));

        $mine = $this->getJson("/api/v1/cashier-shifts?user_id={$this->user->id}")->json('data');
        $this->assertSame([$this->user->name], array_column($mine, 'user_name'));

        $recent = $this->getJson('/api/v1/cashier-shifts?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->json('data');
        $this->assertSame([$this->user->id], array_column($recent, 'user_id'));
    }
}

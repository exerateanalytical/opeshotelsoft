<?php

namespace Tests\Feature\FrontOffice;

use App\Modules\Guests\Domain\Company;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use Tests\TestCase;

/** Workflow: reserve → check in → night audit → pay → check out, with GL postings. */
class StayLifecycleTest extends TestCase
{
    private function bookToday(int $nights = 1, int $rate = 59_625): array
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: $rate);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDays($nights)->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->first();

        return [$r, $room, $guest];
    }

    public function test_full_stay_posts_balanced_entries_and_closes_folio(): void
    {
        [$r, $room] = $this->bookToday();

        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])
            ->assertOk()->assertJsonPath('status', 'checked_in');
        $this->assertDatabaseHas('guest_register_entries', ['reservation_id' => $r->id, 'id_number' => 'CM123456']);

        $audit = $this->postJson('/api/v1/night-audit')->assertOk()->json();
        $this->assertSame(1, $audit['room_nights_posted']);
        $this->assertSame(100.0, (float) $audit['occupancy_percent'] * 2); // 1 of 2 rooms

        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->json();
        // 59 625 room (VAT incl.) + 1 000 tourist tax added
        $this->assertSame(60_625, $folio['balance']);

        $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 10_000])->assertCreated();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'MTN', 'amount' => 60_625, 'reference' => 'MP123'])->assertCreated();

        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk()->assertJsonPath('status', 'checked_out');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'occupied' => false, 'hk_status' => 'vacant_dirty']);

        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['411100'] ?? 0, 'guest ledger cleared');
        $this->assertSame(60_625, $b['521500'], 'mobile money received');
        $this->assertSame(-50_000, $b['706100'], 'net room revenue');
        $this->assertSame(-9_625, $b['443100'], 'VAT collected');
        $this->assertSame(-1_000, $b['447300'], 'tourist tax');
    }

    public function test_checkout_blocked_while_balance_is_open(): void
    {
        [$r, $room] = $this->bookToday();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $this->postJson('/api/v1/night-audit')->assertOk();

        $this->postJson("/api/v1/reservations/{$r->id}/check-out")
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/folio-unbalanced');
    }

    public function test_company_direct_bill_moves_balance_to_city_ledger(): void
    {
        [$r, $room] = $this->bookToday();
        $company = Company::create(['name' => 'SONARA', 'city_ledger' => true, 'credit_limit' => 1_000_000]);
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $this->postJson('/api/v1/night-audit')->assertOk();

        $this->postJson("/api/v1/reservations/{$r->id}/check-out", ['city_ledger_company_id' => $company->id])->assertOk();

        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['411100'] ?? 0);
        $this->assertSame(60_625, $b['411200'], 'company owes the stay');
    }

    public function test_void_needs_permission_and_reverses_gl(): void
    {
        [$r, $room] = $this->bookToday();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $line = $this->postJson("/api/v1/folios/{$folio['id']}/charges", ['charge_code' => 'BAR', 'amount' => 5_000])->assertCreated()->json();

        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel);
        $this->postJson("/api/v1/folios/{$folio['id']}/lines/{$line['id']}/reverse", ['reason' => 'wrong room'])->assertForbidden();

        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/folios/{$folio['id']}/lines/{$line['id']}/reverse", ['reason' => 'wrong room'])->assertCreated();

        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['706300'] ?? 0);
        $this->assertSame(0, $b['411100'] ?? 0);
    }

    public function test_night_audit_is_not_repeated_for_same_night(): void
    {
        [$r, $room] = $this->bookToday(nights: 2);
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $this->postJson('/api/v1/night-audit')->assertOk()->assertJsonPath('room_nights_posted', 1);
        $this->postJson('/api/v1/night-audit')->assertOk()->assertJsonPath('room_nights_posted', 1); // next day posts night 2

        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $this->assertSame(2 * 60_625, $folio['balance']);
    }

    public function test_checkin_requires_id_for_police_register(): void
    {
        [$r, $room, $guest] = $this->bookToday();
        $guest->update(['id_number' => null]);

        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/id-required');
    }

    public function test_cashier_shift_close_reports_variance(): void
    {
        [$r, $room] = $this->bookToday();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $shift = $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 20_000])->json();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'CASH', 'amount' => 30_000])->assertCreated();

        $this->getJson('/api/v1/cashier-shifts/current')->assertOk()->assertJsonPath('expected.CASH', 50_000);

        $closed = $this->postJson("/api/v1/cashier-shifts/{$shift['id']}/close", ['counted' => ['CASH' => 49_000]])->assertOk()->json();
        $this->assertSame(-1_000, $closed['variance']);
    }

    public function test_folio_of_a_finished_stay_is_shown_without_opening_a_new_one(): void
    {
        [$r, $room] = $this->bookToday();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $folioId = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json('id');
        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk();

        $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->assertJsonPath('id', $folioId);
        $this->assertDatabaseCount('folios', 1);
    }
}

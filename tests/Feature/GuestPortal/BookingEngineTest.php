<?php

namespace Tests\Feature\GuestPortal;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Guests\Domain\Company;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use Tests\TestCase;

class BookingEngineTest extends TestCase
{
    public function test_guest_searches_books_looks_up_and_cancels_without_login(): void
    {
        [$type, $plan] = $this->seedInventory(rooms: 2, rate: 45_000);
        $slug = $this->group->slug;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Hotel-Id' => null]);

        $arrival = now()->addDays(7)->toDateString();
        $departure = now()->addDays(9)->toDateString();
        $search = $this->getJson("/api/v1/booking/$slug/availability?arrival=$arrival&departure=$departure&adults=2")->assertOk()->json();
        $offer = $search['results'][0]['offers'][0];
        $this->assertSame(90_000, $offer['total']);

        $booking = $this->postJson("/api/v1/booking/$slug/reservations", [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $offer['room_type']['id'], 'rate_plan_id' => $offer['rate_plan']['id'],
            'arrival' => $arrival, 'departure' => $departure, 'adults' => 2, 'accept_terms' => true,
            'guest' => ['first_name' => 'Nadia', 'last_name' => 'Fotso', 'email' => 'nadia@example.cm', 'phone' => '+237690000000'],
        ])->assertCreated()->json();

        $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $booking['confirmation'], 'last_name' => 'FOTSO'])
            ->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $booking['confirmation'], 'last_name' => 'Wrong'])->assertNotFound();

        $this->postJson("/api/v1/booking/$slug/reservations/cancel", ['confirmation' => $booking['confirmation'], 'token' => 'forged'])->assertForbidden();
        $this->postJson("/api/v1/booking/$slug/reservations/cancel", ['confirmation' => $booking['confirmation'], 'token' => $booking['manage_token']])
            ->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_dashboard_and_city_ledger_payment(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 59_625);
        $guest->update(['id_number' => 'X']);
        $company = Company::create(['name' => 'MTN Cameroon', 'city_ledger' => true]);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'company_id' => $company->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(), 'room_id' => Room::first()->id,
        ]);
        app(FrontDeskService::class)->checkIn($r);

        $dash = $this->getJson('/api/v1/reports/dashboard')->assertOk()->json();
        $this->assertSame(1, $dash['today']['rooms_sold']);
        $this->assertEquals(50.0, $dash['today']['occupancy_percent']);

        $this->postJson('/api/v1/night-audit')->assertOk();
        app(FrontDeskService::class)->checkOut($r->fresh(), ['city_ledger_company_id' => $company->id]);
        $ledger = Folio::where('kind', 'city_ledger')->first();
        $invoice = app(FolioService::class)->invoice($ledger);

        $this->postJson("/api/v1/receivables/invoices/{$invoice->id}/payments", ['amount' => 60_625, 'method' => 'XFER', 'reference' => 'VIR-1'])
            ->assertOk()->assertJsonPath('status', 'paid');
        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['411200'] ?? 0, 'company paid');
        $this->assertSame(60_625, $b['521100']);

        $this->getJson('/api/v1/reports/group-dashboard')->assertOk()->assertJsonPath('total.rooms_available', 2);
    }
}

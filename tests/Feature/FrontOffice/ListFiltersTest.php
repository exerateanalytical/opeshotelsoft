<?php

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Domain\GuestRegisterEntry;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Search / filter / export parameters on front-office list endpoints. */
class ListFiltersTest extends TestCase
{
    private function checkedIn(): array
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->json();

        return [$r, $guest, $folio];
    }

    public function test_folios_filter_by_guest_of_reservation_and_search(): void
    {
        [, $guest, $folio] = $this->checkedIn();
        $other = Guest::create(['first_name' => 'Autre', 'last_name' => 'Client']);
        $this->postJson('/api/v1/folios', ['kind' => 'house', 'name' => 'House account'])->assertCreated();

        $ids = $this->getJson("/api/v1/folios?status=open&guest_id={$guest->id}")->assertOk()->json('data.*.id');
        $this->assertSame([$folio['id']], $ids);
        $this->assertSame([], $this->getJson("/api/v1/folios?guest_id={$other->id}")->json('data'));

        $this->assertSame([$folio['id']], $this->getJson('/api/v1/folios?q='.urlencode($folio['number']))->json('data.*.id'));
        $this->assertCount(1, $this->getJson('/api/v1/folios?q=house')->json('data'));
    }

    public function test_invoices_search_and_date_range(): void
    {
        foreach ([['INV-1', 'SONARA SA', '2026-10-01'], ['INV-2', 'Hôtel Client', '2026-10-20']] as [$n, $name, $date]) {
            Invoice::create(['number' => $n, 'bill_to_name' => $name, 'issue_date' => $date, 'currency' => 'XAF',
                'total' => 1000, 'tax_total' => 0, 'lines' => [], 'taxes' => []]);
        }

        $this->assertSame(['INV-1'], $this->getJson('/api/v1/invoices?q=sonara')->assertOk()->json('data.*.number'));
        $this->assertSame(['INV-2'], $this->getJson('/api/v1/invoices?q=INV-2')->json('data.*.number'));
        $this->assertSame(['INV-2'], $this->getJson('/api/v1/invoices?from=2026-10-10&to=2026-10-31')->json('data.*.number'));
    }

    public function test_business_days_date_range_and_per_page(): void
    {
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-10-01'] as $d) {
            DB::table('business_days')->insert(['hotel_id' => $this->hotel->id, 'date' => $d, 'status' => 'closed']);
        }

        $res = $this->getJson('/api/v1/business-days?from=2026-09-01&to=2026-09-30&per_page=2')->assertOk();
        $res->assertJsonPath('total', 3)->assertJsonPath('per_page', 2)->assertJsonCount(2, 'data');
        $this->assertSame('2026-09-03', substr($res->json('data.0.date'), 0, 10));
        $this->getJson('/api/v1/business-days?per_page=500')->assertStatus(422);
    }

    public function test_police_register_csv_export(): void
    {
        [$r] = $this->checkedIn();
        GuestRegisterEntry::where('reservation_id', $r->id)->update(['full_name' => 'Hélène Ngô', 'profession' => 'Ingénieure']);
        $from = now()->toDateString();

        $res = $this->get("/api/v1/police-register?from={$from}&to={$from}&format=csv")->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.csv', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('room_number;full_name', $body);
        $this->assertStringContainsString('Hélène Ngô', $body);
        $this->assertStringContainsString('CM123456', $body);

        $this->getJson("/api/v1/police-register?from={$from}&to={$from}")->assertOk()->assertJsonPath('0.full_name', 'Hélène Ngô');
    }
}

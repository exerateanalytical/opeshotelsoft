<?php

namespace Tests\Feature\Reservations;

use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Reservations\Revenue\Domain\UpsellOffer;
use Illuminate\Support\Facades\DB;

class RevenueUpsellTest extends RevenueBaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRevenue(stdRooms: 2, dlxRooms: 2);
    }

    public function test_pre_arrival_offer_is_priced_per_night_difference_and_accept_updates_reservation(): void
    {
        $r = $this->book($this->std, 5, 2);
        $offers = $this->postJson("/api/v1/revenue/reservations/{$r->id}/upsell-offers", ['stage' => 'pre_arrival'])->assertCreated()->json('data');

        // DLX 80k - STD 50k = 30k, less the 10% pre-arrival discount = 27k per night.
        $this->assertCount(1, $offers);
        $this->assertSame(27_000, $offers[0]['price_per_night']);
        $this->assertSame(54_000, $offers[0]['total']);

        $before = $r->total_amount;
        $this->postJson("/api/v1/revenue/upsell-offers/{$offers[0]['id']}/accept")->assertSuccessful()->assertJsonPath('status', 'accepted');

        $r->refresh();
        $this->assertSame($this->dlx->id, $r->room_type_id);
        $this->assertSame($before + 54_000, (int) $r->total_amount);
        $this->assertSame([77_000, 77_000], $r->nights()->orderBy('date')->pluck('amount')->map(fn ($a) => (int) $a)->all());
        $this->assertSame(0, (int) DB::table('room_type_inventory')->where('room_type_id', $this->std->id)->where('date', $this->day(5))->value('sold'));
        $this->assertSame(1, (int) DB::table('room_type_inventory')->where('room_type_id', $this->dlx->id)->where('date', $this->day(5))->value('sold'));
        $this->postJson("/api/v1/revenue/upsell-offers/{$offers[0]['id']}/accept")->assertStatus(409);
    }

    public function test_decline_and_sold_out_target(): void
    {
        $r = $this->book($this->std, 5, 1);
        $offer = $this->postJson("/api/v1/revenue/reservations/{$r->id}/upsell-offers", ['stage' => 'booking'])->json('data.0');
        $this->assertSame(30_000, $offer['price_per_night'], 'no discount at booking');
        $this->postJson("/api/v1/revenue/upsell-offers/{$offer['id']}/decline")->assertSuccessful()->assertJsonPath('status', 'declined');

        $offer = $this->postJson("/api/v1/revenue/reservations/{$r->id}/upsell-offers", ['stage' => 'booking'])->json('data.0');
        $this->book($this->dlx, 5, 1);
        $this->book($this->dlx, 5, 1);
        $this->postJson("/api/v1/revenue/upsell-offers/{$offer['id']}/accept")->assertStatus(409);
        $this->assertSame($this->std->id, $r->refresh()->room_type_id, 'nothing changed');
        $this->assertSame('offered', UpsellOffer::find($offer['id'])->status);
        $this->postJson("/api/v1/revenue/reservations/{$r->id}/upsell-offers", ['stage' => 'booking'])->assertCreated()->assertJsonCount(0, 'data');
    }

    public function test_check_in_upgrade_posts_to_the_folio_and_books_balance(): void
    {
        $r = $this->book($this->std, 0, 2);
        $this->guest->update(['id_type' => 'national_id', 'id_number' => 'CM123456']);
        $room = $r->roomType->rooms()->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();

        $offer = $this->postJson("/api/v1/revenue/reservations/{$r->id}/upsell-offers", ['stage' => 'check_in'])->assertCreated()->json('data.0');
        $this->assertSame(25_500, $offer['price_per_night'], '15% check-in discount');

        $this->postJson("/api/v1/revenue/upsell-offers/{$offer['id']}/accept")->assertStatus(422);
        $newRoom = $this->dlx->rooms()->first();
        $this->postJson("/api/v1/revenue/upsell-offers/{$offer['id']}/accept", ['room_id' => $newRoom->id])->assertSuccessful();

        $r->refresh();
        $this->assertSame($newRoom->id, $r->room_id);
        $this->assertSame($this->dlx->id, $r->room_type_id);
        $line = FolioLine::where('source_type', 'upsell')->first();
        $this->assertNotNull($line);
        $this->assertGreaterThanOrEqual(51_000, (int) $line->amount, "upgrade plus applicable room taxes");
        $this->assertSame($line->id, UpsellOffer::find($offer['id'])->folio_line_id);
        $this->assertBooksBalance();
    }

    public function test_guest_accepts_over_the_public_booking_api(): void
    {
        $slug = $this->group->slug;
        $this->anonymous();
        $b = $this->postJson("/api/v1/booking/$slug/reservations", [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $this->std->id, 'rate_plan_id' => $this->bar->id,
            'arrival' => $this->day(4), 'departure' => $this->day(6), 'adults' => 1, 'accept_terms' => true,
            'guest' => ['first_name' => 'Awa', 'last_name' => 'Ngono', 'email' => 'awa@example.cm', 'phone' => '+237670000000'],
        ])->assertCreated()->json();
        $this->assertCount(1, $b['upsell_offers']);

        $creds = ['confirmation' => $b['confirmation'], 'token' => $b['manage_token']];
        $this->postJson("/api/v1/booking/$slug/upsell/offers", ['confirmation' => $b['confirmation'], 'token' => 'bad'])->assertForbidden();
        $offers = $this->postJson("/api/v1/booking/$slug/upsell/offers", $creds)->assertOk()->json('offers');
        $this->assertSame($b['upsell_offers'][0]['id'], $offers[0]['id']);

        $this->postJson("/api/v1/booking/$slug/upsell/accept", $creds + ['offer_id' => $offers[0]['id']])->assertOk()
            ->assertJsonPath('status', 'accepted')->assertJsonPath('total', $b['total'] + 60_000);
    }

    public function test_pre_arrival_run_and_permissions(): void
    {
        $this->book($this->std, 3, 1);
        $this->asRole('receptionist');
        $this->postJson('/api/v1/revenue/upsell-offers/pre-arrival-run', ['days_ahead' => 3])->assertForbidden();
        $this->asRole('revenue-manager');
        $this->postJson('/api/v1/revenue/upsell-offers/pre-arrival-run', ['days_ahead' => 3])->assertSuccessful()->assertJsonPath('reservations_offered', 1);
        $this->assertSame(1, UpsellOffer::where('stage', 'pre_arrival')->count());
    }
}

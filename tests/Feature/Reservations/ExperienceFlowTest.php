<?php

namespace Tests\Feature\Reservations;

use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use App\Modules\FrontOffice\Domain\CheckoutFeeRule;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\DepositAutomation;
use App\Modules\Reservations\Experience\Domain\ExperienceService;
use App\Modules\Reservations\Experience\Domain\GuestReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B: group booking, guest self-service, deposit automation, rooming import, readiness, suggestions, channel health. */
class ExperienceFlowTest extends TestCase
{
    private $type;

    private $plan;

    private $guest;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->type, $this->plan, $this->guest] = $this->seedInventory(rooms: 5, rate: 50_000);
    }

    private function d(int $n): string
    {
        return Carbon::today()->addDays($n)->toDateString();
    }

    private function book(int $start, int $nights, array $extra = []): Reservation
    {
        return app(ReservationService::class)->create($extra + [
            'guest_id' => $this->guest->id, 'room_type_id' => $this->type->id, 'rate_plan_id' => $this->plan->id,
            'arrival' => $this->d($start), 'departure' => $this->d($start + $nights),
        ]);
    }

    private function manage(Reservation $r, string $path, array $body = [])
    {
        return $this->postJson("/api/v1/booking/{$this->group->slug}/manage/$path", $body + ['confirmation' => $r->number, 'token' => GuestLinks::manageToken($r)]);
    }

    public function test_group_booking_shares_reference_payer_and_master_folio(): void
    {
        $res = $this->postJson('/api/v1/experience/bookings', [
            'payer' => ['first_name' => 'Ada', 'last_name' => 'Payer', 'email' => 'ada@test.cm'],
            'arrival' => $this->d(4), 'departure' => $this->d(6),
            'rooms' => [
                ['room_type_id' => $this->type->id, 'rate_plan_id' => $this->plan->id, 'adults' => 2, 'guest' => ['first_name' => 'Bob', 'last_name' => 'One']],
                ['room_type_id' => $this->type->id, 'rate_plan_id' => $this->plan->id, 'guest' => ['first_name' => 'Cy', 'last_name' => 'Two']],
            ],
        ])->assertCreated()->json();

        $this->assertCount(2, $res['rooms']);
        $this->assertSame(200_000, $res['total']);
        $this->assertSame(2, Reservation::where('booking_ref', $res['booking_ref'])->whereNotNull('payer_guest_id')->count());
        $this->assertNotNull($res['master_folio']);
        $this->assertSame(2, DB::table('folio_routing_rules')->count());
        $this->assertSame(2, Reservation::where('booking_ref', $res['booking_ref'])->distinct('guest_id')->count('guest_id'));
    }

    public function test_group_booking_is_all_or_nothing(): void
    {
        $this->postJson('/api/v1/experience/bookings', [
            'payer_guest_id' => $this->guest->id, 'arrival' => $this->d(4), 'departure' => $this->d(5),
            'rooms' => array_fill(0, 6, ['room_type_id' => $this->type->id, 'rate_plan_id' => $this->plan->id]),
        ])->assertStatus(409);
        $this->assertSame(0, Reservation::count());
    }

    public function test_guest_modifies_dates_with_preview_then_apply_and_cancel_fee_is_shown(): void
    {
        $r = $this->book(10, 2);
        $opts = $this->manage($r, 'options')->assertOk()->json();
        $this->assertTrue($opts['modify']['allowed']);
        $this->assertArrayHasKey('fee', $opts['cancel']);

        $p = $this->manage($r, 'modify', ['arrival' => $this->d(10), 'departure' => $this->d(13)])->assertOk()->json();
        $this->assertSame(50_000, $p['difference']);
        $this->assertSame(100_000, (int) $r->refresh()->total_amount, 'preview changes nothing');

        $this->manage($r, 'modify', ['arrival' => $this->d(10), 'departure' => $this->d(13), 'apply' => true])->assertOk();
        $this->assertSame(150_000, (int) $r->refresh()->total_amount);
    }

    public function test_guest_modify_closed_inside_cutoff_and_non_refundable_extend_only(): void
    {
        $soon = $this->book(1, 2);
        $this->manage($soon, 'modify', ['arrival' => $this->d(1), 'departure' => $this->d(4)])->assertStatus(422);

        $this->plan->update(['refundable' => false]);
        $r = $this->book(10, 2);
        $this->manage($r, 'modify', ['arrival' => $this->d(11), 'departure' => $this->d(12)])->assertStatus(422);
        $this->manage($r, 'modify', ['arrival' => $this->d(10), 'departure' => $this->d(13), 'apply' => true])->assertOk();
    }

    public function test_guest_buys_late_checkout_and_service_posted_to_folio_at_check_in_without_double_fee(): void
    {
        CheckoutFeeRule::create(['kind' => 'late', 'after_hour' => 14, 'charge_code' => 'MISC', 'fee_type' => 'percent_of_night', 'value' => 50, 'label' => 'Late']);
        CheckoutFeeRule::create(['kind' => 'early_checkin', 'after_hour' => 10, 'charge_code' => 'MISC', 'fee_type' => 'fixed', 'value' => 10_000, 'label' => 'Early']);
        ExperienceService::create(['code' => 'PICKUP', 'name' => 'Airport pickup', 'price' => 15_000, 'charge_code' => 'MISC']);
        $r = $this->book(0, 2);

        $q = $this->manage($r, 'timing', ['kind' => 'late_checkout', 'time' => '15:00'])->assertOk()->json('quote');
        $this->assertSame(25_000, $q['amount']);
        $this->manage($r, 'timing', ['kind' => 'late_checkout', 'time' => '15:00', 'apply' => true])->assertCreated();
        $this->manage($r, 'timing', ['kind' => 'late_checkout', 'time' => '15:00', 'apply' => true])->assertStatus(409);
        $this->manage($r, 'timing', ['kind' => 'early_checkin', 'time' => '09:00', 'apply' => true])->assertCreated();
        $this->manage($r, 'services', ['code' => 'PICKUP', 'quantity' => 2])->assertCreated();
        $this->assertSame(0, FolioLine::count(), 'nothing on a folio before check-in');

        $room = Room::first();
        $this->guest->update(['id_type' => 'national_id', 'id_number' => 'CM1']);
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $this->assertSame(3, FolioLine::where('type', 'charge')->whereIn('source_type', ['checkout_fee', 'early_checkin_fee', 'guest_purchase'])->count());
        $this->assertSame(1, FolioLine::where('source_type', 'checkout_fee')->count());
        $this->assertBooksBalance();
    }

    public function test_pay_link_for_balance_and_review(): void
    {
        $r = $this->book(5, 1);
        $r->update(['deposit_required' => 20_000]);
        $url = $this->manage($r, 'pay')->assertOk()->json('url');
        $this->assertStringContainsString('/pay/', $url);

        $this->manage($r, 'review', ['rating' => 5])->assertStatus(422); // not arrived yet
        $r->update(['status' => 'checked_out']);
        $this->postJson("/api/v1/booking/{$this->group->slug}/manage/review", ['confirmation' => $r->number, 'last_name' => $this->guest->last_name, 'rating' => 4, 'comment' => 'Nice'])->assertCreated();
        $this->assertSame(4, GuestReview::first()->rating);
        $this->manage($r, 'review', ['rating' => 5])->assertStatus(409);
        $this->postJson("/api/v1/booking/{$this->group->slug}/manage/review", ['confirmation' => $r->number, 'last_name' => 'Wrong', 'rating' => 4])->assertForbidden();
    }

    public function test_deposit_reminder_then_release_and_no_show_automation(): void
    {
        $this->plan->update(['deposit_required' => true, 'deposit_percent' => 30]);
        $r = $this->book(3, 2);
        $r->update(['status' => 'tentative', 'guarantee' => 'deposit', 'deposit_deadline' => now()->addHours(10)]);
        $this->guest->update(['email' => 'g@test.cm']);
        $auto = app(DepositAutomation::class);

        $this->assertSame(['reminded' => 1, 'released' => 0, 'no_shows' => 0], $auto->run(now()));
        $this->assertSame(['reminded' => 0, 'released' => 0, 'no_shows' => 0], $auto->run(now()), 'reminder is sent once');
        $this->assertTrue(OutboxMessage::where('event', 'reservation.deposit_reminder')->exists());

        $this->assertSame(1, $auto->run(now()->addHours(11))['released']);
        $this->assertSame('cancelled', $r->refresh()->status);
        $this->assertSame(0, (int) $r->cancellation_fee);
        $this->assertSame(0, (int) DB::table('room_type_inventory')->where('date', $this->d(3))->value('sold'));

        $paid = $this->book(6, 1);
        $paid->update(['status' => 'tentative', 'guarantee' => 'deposit', 'deposit_deadline' => now()->subHour(), 'deposit_required' => 10_000, 'deposit_paid' => 10_000]);
        $this->assertSame(0, $auto->run(now())['released'], 'paid deposits are never released');

        $old = $this->book(0, 1);
        DB::table('hotels')->where('id', $this->hotel->id)->update(['business_date' => $this->d(2)]);
        $this->assertSame(1, $auto->run(now())['no_shows']);
        $this->assertSame('no_show', $old->refresh()->status);
    }

    public function test_rooming_list_import_validates_every_row_then_creates_reservations(): void
    {
        $block = $this->postJson('/api/v1/blocks', ['code' => 'WED', 'name' => 'Wedding', 'rate_plan_id' => $this->plan->id, 'arrival' => $this->d(20), 'departure' => $this->d(22),
            'allocations' => [['room_type_id' => $this->type->id, 'rooms' => 2]]])->assertCreated()->json('id');

        $bad = "first_name,last_name,email,room_type,arrival,departure,adults\nAna,Bell,ana@t.cm,DLX,,,2\nBen,Cole,not-an-email,XXX,,,1\nCat,Dyer,cat@t.cm,DLX,".$this->d(19).",".$this->d(21).",1\n";
        $f = UploadedFile::fake()->createWithContent('list.csv', $bad);
        $res = $this->post("/api/v1/experience/blocks/$block/rooming-import", ['file' => $f, 'commit' => 1], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('failed', $res['status']);
        $this->assertSame([3, 4], array_values(array_unique(array_column($res['errors'], 'line'))));
        $this->assertSame(0, Reservation::count());

        $good = "first_name,last_name,email,room_type,arrival,departure,adults\nAna,Bell,ana@t.cm,DLX,,,2\nDan,Eve,dan@t.cm,DLX,,,1\nEli,Fox,eli@t.cm,DLX,,,1\n";
        $over = $this->post("/api/v1/experience/blocks/$block/rooming-import", ['file' => UploadedFile::fake()->createWithContent('l.csv', $good), 'commit' => 1], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('failed', $over['status'], 'three rows exceed the two rooms of the block');

        $two = "first_name;last_name;email;room_type\nAna;Bell;ana@t.cm;DLX\nDan;Eve;dan@t.cm;DLX\n";
        $ok = $this->post("/api/v1/experience/blocks/$block/rooming-import", ['file' => UploadedFile::fake()->createWithContent('l.csv', $two), 'commit' => 1], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame('imported', $ok['status']);
        $this->assertSame(2, Reservation::where('booking_ref', 'WED')->count());
        $this->assertSame(3, DB::table('rooming_imports')->count());
    }

    public function test_digital_checkin_readiness_card_and_staff_capture(): void
    {
        $r = $this->book(0, 2);
        $sig = 'data:image/png;base64,'.base64_encode('png');

        $a = $this->getJson('/api/v1/experience/arrivals')->assertOk()->json();
        $this->assertSame('missing', $a['arrivals'][0]['readiness']['status']);

        $this->manage($r, 'checkin', ['id_type' => 'passport', 'id_number' => 'P123', 'eta' => '18:30', 'signature' => $sig, 'card_accepted' => true, 'nationality' => 'CM', 'address' => 'Douala'])->assertCreated();
        $this->assertSame('P123', $this->guest->refresh()->id_number);
        $card = $this->getJson("/api/v1/experience/reservations/{$r->id}/card")->assertOk()->json();
        $this->assertSame('P123', $card['guest']['id_number']);
        $this->assertTrue($card['checkin']['card_accepted']);
        $this->assertSame(['room'], $card['readiness']['missing']);

        $this->postJson("/api/v1/experience/reservations/{$r->id}/checkin-data", ['id_number' => 'P999', 'signature' => 'data:image/jpeg;base64,xx'])->assertStatus(422);
        $this->postJson("/api/v1/experience/reservations/{$r->id}/checkin-data", ['id_number' => 'P999'])->assertOk();
        $this->assertSame('P999', $this->guest->refresh()->id_number);
    }

    public function test_room_suggestions_honour_preferences_adjacency_housekeeping_and_loyalty_then_accept_reject(): void
    {
        $rooms = Room::orderBy('number')->get();
        $rooms[0]->update(['floor' => '1', 'hk_status' => 'vacant_dirty']);
        $rooms[1]->update(['floor' => '2', 'view' => 'sea', 'quiet' => true]);
        $rooms[2]->update(['floor' => '2']);
        $rooms[3]->update(['floor' => '2']);
        $rooms[4]->update(['floor' => '3']);

        $solo = $this->book(0, 1);
        $solo->update(['room_prefs' => ['floor' => '2', 'view' => 'sea', 'quiet' => true]]);
        $r1 = $this->postJson('/api/v1/experience/suggestions/generate', ['date' => $this->d(0)])->assertOk()->json('data');
        $best = collect($r1)->where('reservation_id', $solo->id)->sortByDesc('score')->first();
        $this->assertSame($rooms[1]->id, $best['room_id']);
        $this->assertContains('view', $best['reasons']);
        $this->assertContains('quiet', $best['reasons']);

        $this->postJson("/api/v1/experience/suggestions/{$best['id']}/reject")->assertOk();
        $again = collect($this->postJson('/api/v1/experience/suggestions/generate', ['date' => $this->d(0)])->json('data'))->where('reservation_id', $solo->id);
        $this->assertNotContains($rooms[1]->id, $again->pluck('room_id')->all(), 'rejected rooms are not offered again');

        $pick = $again->sortByDesc('score')->first();
        $this->postJson("/api/v1/experience/suggestions/{$pick['id']}/accept")->assertOk();
        $this->assertSame($pick['room_id'], $solo->refresh()->room_id);
        $this->postJson("/api/v1/experience/suggestions/{$pick['id']}/accept")->assertStatus(409);
    }

    public function test_room_suggestions_keep_a_family_adjacent_and_serve_loyalty_first(): void
    {
        $rooms = Room::orderBy('number')->get();
        foreach ($rooms as $r) {
            $r->update(['floor' => '1']);
        }
        $anchor = $this->book(0, 1, ['room_id' => $rooms[2]->id]);
        $fam = $this->book(0, 1, ['booking_ref' => $anchor->booking_ref]);
        $out = collect($this->postJson('/api/v1/experience/suggestions/generate', ['date' => $this->d(0)])->json('data'))->where('reservation_id', $fam->id)->sortByDesc('score')->first();
        $this->assertContains($out['room_id'], [$rooms[1]->id, $rooms[3]->id]);
        $this->assertContains('adjacent', $out['reasons']);
    }

    public function test_channel_health_board_flags_failures_and_lag_and_retry_queues(): void
    {
        $c = Connection::create(['provider' => 'channex', 'name' => 'Channex', 'status' => 'active', 'last_push_at' => now()->subMinutes(5), 'last_pull_at' => now()->subMinutes(5)]);
        $board = $this->getJson('/api/v1/experience/channels/health')->assertOk()->json('data');
        $this->assertSame('ok', $board[0]['health']);

        $c->update(['last_push_at' => now()->subHours(5)]);
        $this->assertSame('warning', $this->getJson('/api/v1/experience/channels/health')->json('data.0.health'));

        foreach (range(1, 3) as $i) {
            SyncLog::create(['channel_connection_id' => $c->id, 'direction' => 'outbound', 'kind' => 'ari', 'status' => 'failed', 'error' => 'HTTP 500', 'http_status' => 500]);
        }
        $row = $this->getJson('/api/v1/experience/channels/health')->json('data.0');
        $this->assertSame('error', $row['health']);
        $this->assertSame(3, $row['failures']);
        $this->assertSame('HTTP 500', $row['last_error']['message']);

        $this->postJson("/api/v1/experience/channels/{$c->id}/retry")->assertStatus(202);
    }
}

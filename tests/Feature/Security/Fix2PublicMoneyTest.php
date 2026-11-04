<?php

namespace Tests\Feature\Security;

use App\Modules\Channels\Domain\Connection;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Guests\Application\PrivacyService;
use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Guests\Domain\PrivacyRequest;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\DigitalCheckin;
use App\Modules\Reservations\Revenue\Application\BookingSiteRevenue;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

/** SEC2 regressions: public endpoints, money integrity and erasure (medium and low findings). */
class Fix2PublicMoneyTest extends TestCase
{
    use RepairsRoleSeed;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function reservation(int $inDays = 3): Reservation
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2);

        return app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays($inDays)->toDateString(), 'departure' => now()->addDays($inDays + 2)->toDateString(),
        ]);
    }

    /** Post without a cashier shift (no actor): the shift rule is not what these tests are about. */
    private function noActor(): void
    {
        app(TenantContext::class)->set($this->group->id, $this->hotel->id, [$this->hotel->id], null);
    }

    /** public:booking:corporate-code-low-entropy-bills-company */
    public function test_corporate_codes_are_generated_and_public_lookups_are_throttled(): void
    {
        [, $plan] = $this->seedInventory(rooms: 1);
        $company = Company::create(['name' => 'SONARA']);
        $c = $this->postJson('/api/v1/revenue/contracts', [
            'company_id' => $company->id, 'code' => 'sonara', 'name' => 'SONARA 2026', 'parent_rate_plan_id' => $plan->id, 'discount_percent' => 20,
            'valid_from' => now()->toDateString(), 'valid_to' => now()->addYear()->toDateString(),
        ])->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^SONARA-[0-9A-HJKMNP-TV-Z]{13}$/', $c['code'], 'readable prefix + 65 random bits');
        $this->assertNotSame('SONARA', $c['code']);

        $revenue = app(BookingSiteRevenue::class);
        $try = fn (string $code) => $revenue->prepare(['arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
            'room_type_id' => 0, 'rate_plan_id' => 0, 'corporate_code' => $code]);
        for ($i = 0; $i < 20; $i++) {
            try {
                $try('GUESS'.$i);
                $this->fail('a wrong code must be refused');
            } catch (DomainException $e) {
                $this->assertStringContainsString('corporate code is not valid', $e->getMessage());
            }
        }
        try {
            $try('GUESS-21');
            $this->fail('the 21st failed lookup from one IP must be throttled');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
    }

    /** race:folio:refund-check-on-stale-balance */
    public function test_refund_is_rechecked_against_the_locked_balance(): void
    {
        $this->noActor();
        $folios = app(FolioService::class);
        $folio = $folios->open('guest', 'Walk-in');
        $folios->payment($folio, 'CASH', 10_000);
        $stale = Folio::findOrFail($folio->id); // balance -10 000, read before either refund
        $stale2 = Folio::findOrFail($folio->id);
        $folios->refund($stale, 'CASH', 10_000, 'Overpaid');
        try {
            $folios->refund($stale2, 'CASH', 10_000, 'Second click');
            $this->fail('the second refund must be refused on the locked row');
        } catch (DomainException) {
        }
        $this->assertSame(0, (int) $folio->fresh()->balance);
    }

    /** race:folio:open-number-sequence-outside-transaction */
    public function test_guest_folio_is_opened_once_per_reservation(): void
    {
        $r = $this->reservation();
        $folios = app(FolioService::class);
        $a = $folios->guestFolioFor($r);
        $b = $folios->guestFolioFor($r->fresh());
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Folio::where('reservation_id', $r->id)->where('kind', 'guest')->count());
    }

    /** public:checkin:id-photo-no-magic-check */
    public function test_digital_checkin_id_photo_must_really_be_an_image(): void
    {
        $r = $this->reservation();
        $svc = app(DigitalCheckin::class);
        try {
            $svc->submit($r, ['id_number' => 'P1', 'id_photo' => 'data:image/png;base64,'.base64_encode('<?php system($_GET["c"]);')]);
            $this->fail('non-image bytes must be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('JPEG, PNG or WebP', $e->getMessage());
        }
        $row = $svc->submit($r, ['id_number' => 'P1', 'id_photo' => self::PNG]);
        $this->assertStringEndsWith('.png', $row->id_photo_path);
    }

    /** public:channels:sandbox-unsigned-inbound */
    public function test_sandbox_channel_requires_a_signed_inbound_booking(): void
    {
        $c = $this->postJson('/api/v1/channels/connections', ['provider' => 'channex', 'name' => 'Channex', 'environment' => 'sandbox', 'status' => 'active'])
            ->assertCreated()->json();
        $this->assertStringStartsWith('chsec_', $c['inbound_secret']);
        $this->assertArrayNotHasKey('inbound_secret', $this->getJson('/api/v1/channels/connections')->json('0'));
        $body = json_encode(['event' => 'booking', 'payload' => []]);
        $this->call('POST', "/api/v1/channels/inbound/{$c['uuid']}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertStatus(401);
        $sig = 'sha256='.hash_hmac('sha256', $body, $c['inbound_secret']);
        $this->call('POST', "/api/v1/channels/inbound/{$c['uuid']}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertNotNull(Connection::find($c['id'])->inbound_secret);
    }

    /** dos:accounting:general-ledger-unbounded-date-range */
    public function test_general_ledger_range_is_capped_and_lines_are_paged(): void
    {
        $this->getJson('/api/v1/accounting/reports/general-ledger?account=571100&from=2020-01-01&to=2026-01-01')->assertStatus(422);
        $this->noActor();
        $folios = app(FolioService::class);
        $folio = $folios->open('guest', 'Walk-in');
        $folios->payment($folio, 'CASH', 1_000);
        $folios->payment($folio, 'CASH', 2_000);
        $this->actAs($this->user, $this->hotel);
        $account = DB::table('journal_lines')->where('hotel_id', $this->hotel->id)->where('debit', 1_000)->value('account_code');
        $this->assertNotNull($account, 'payment posted to the ledger');
        $q = "/api/v1/accounting/reports/general-ledger?account=$account&from=".now()->subDays(5)->toDateString().'&to='.now()->addDay()->toDateString();
        $all = $this->getJson($q)->assertOk()->json();
        $p1 = $this->getJson("$q&per_page=1&page=1")->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(1, 'lines')->json();
        $p2 = $this->getJson("$q&per_page=1&page=2")->assertOk()->assertJsonPath('has_more', false)->json();
        $this->assertSame($all['lines'][1]['balance'], $p2['lines'][0]['balance'], 'running balance carries the earlier page');
        $this->assertSame($all['closing'], $p2['closing']);
        $this->assertSame(2, $p1['total_lines']);
    }

    /** retention:guests:erasure-does-not-cover-financial-and-payment-copies-of-personal-data */
    public function test_erasure_covers_folio_invoice_payment_and_checkin_copies_without_touching_amounts(): void
    {
        $r = $this->reservation();
        $guest = $r->guest;
        $guest->update(['phone' => '690112233']);
        $this->noActor();
        $folios = app(FolioService::class);
        $folio = $folios->guestFolioFor($r);
        $folios->charge($folio, 'MISC', 25_000);
        $folios->payment($folio, 'CASH', 25_000);
        $invoice = $folios->invoice($folio->fresh(), ['address' => 'Rue 12, Akwa']);
        $base = ['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'created_at' => now(), 'updated_at' => now()];
        $ref = (string) \Illuminate\Support\Str::uuid();
        DB::table('payment_intents')->insert($base + ['reference' => $ref,'provider' => 'mtn', 'driver' => 'dry_run', 'amount' => 25_000,
            'folio_id' => $folio->id, 'reservation_id' => $r->id, 'payer_phone' => '237690112233', 'payer_email' => 'jean@test.cm']);
        DB::table('payment_links')->insert($base + ['code' => 'PL-1', 'target_type' => 'reservation', 'target_id' => $r->id, 'amount' => 25_000, 'payer_phone' => '237690112233']);
        DB::table('guest_portal_checkins')->insert($base + ['reservation_id' => $r->id, 'id_type' => 'passport', 'id_number' => 'P12345', 'address' => 'Rue 12', 'submitted_at' => now()]);
        $this->actAs($this->user, $this->hotel);
        $before = $this->assertBooksBalance();
        $invoiceTotal = (int) DB::table('invoices')->where('id', $invoice->id)->value('total');

        $privacy = app(PrivacyService::class);
        $req = $privacy->request($guest, 'anonymise', 'Guest asked');
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $privacy->approve(PrivacyRequest::findOrFail($req->id));

        $this->assertStringStartsWith('Anonymised', (string) Folio::find($folio->id)->name);
        $inv = DB::table('invoices')->where('id', $invoice->id)->first();
        $this->assertStringStartsWith('Anonymised', $inv->bill_to_name);
        $this->assertNull($inv->bill_to_address);
        $this->assertSame($invoiceTotal, (int) $inv->total);
        $this->assertSame($invoice->number, $inv->number);
        $pi = DB::table('payment_intents')->where('reference', $ref)->first();
        $this->assertNull($pi->payer_phone);
        $this->assertNull($pi->payer_email);
        $this->assertSame(25_000, (int) $pi->amount);
        $this->assertNull(DB::table('payment_links')->where('code', 'PL-1')->value('payer_phone'));
        $ci = DB::table('guest_portal_checkins')->where('reservation_id', $r->id)->first();
        $this->assertSame('', $ci->id_number);
        $this->assertNull($ci->address);
        $this->assertSame($before, $this->assertBooksBalance(), 'the books are untouched');
        $this->assertSame(Guest::class, get_class(Guest::withTrashed()->find($guest->id)));
    }
}

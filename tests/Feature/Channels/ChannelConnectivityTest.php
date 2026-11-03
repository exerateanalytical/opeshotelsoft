<?php

namespace Tests\Feature\Channels;

use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelConnectivityTest extends TestCase
{
    private function connect(string $provider, array $extra = []): array
    {
        [$type, $plan] = $this->seedInventory(rooms: 3, rate: 50_000);
        $c = $this->postJson('/api/v1/channels/connections', [
            'provider' => $provider, 'name' => $provider, 'property_code' => 'HOTEL-123', 'status' => 'active',
        ] + $extra)->assertCreated()->json();
        $this->putJson("/api/v1/channels/connections/{$c['id']}/mappings", [
            'rooms' => [['room_type_id' => $type->id, 'external_room_id' => 'DLX-EXT']],
            'rates' => [['rate_plan_id' => $plan->id, 'external_rate_id' => 'BAR-EXT', 'markup_percent' => 15]],
        ])->assertOk();

        return [$c, $type, $plan];
    }

    public function test_rate_change_pushes_ari_to_live_channels(): void
    {
        [$c, $type, $plan] = $this->connect('channex');
        $day = now()->addDays(3)->toDateString();

        $this->putJson('/api/v1/rates', ['rate_plan_id' => $plan->id, 'room_type_ids' => [$type->id], 'from' => $day, 'to' => $day, 'amount' => 70_000])->assertOk();

        $log = SyncLog::where('channel_connection_id', $c['id'])->where('kind', 'ari')->latest('id')->first();
        $this->assertNotNull($log, 'ARI push was logged');
        $this->assertSame('dry_run', $log->status, 'no credentials → dry run');
        $this->assertStringContainsString('"rate":"80500"', $log->request, '70 000 + 15% markup');
    }

    public function test_live_send_uses_credentials_and_records_response(): void
    {
        Http::fake(['staging.channex.io/*' => Http::response(['data' => []], 200)]);
        [$c, $type, $plan] = $this->connect('channex', ['credentials' => ['api_key' => 'test-key'], 'environment' => 'sandbox']);

        $this->postJson("/api/v1/channels/connections/{$c['id']}/sync", ['from' => now()->toDateString(), 'to' => now()->addDays(2)->toDateString()])->assertStatus(202);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'staging.channex.io/api/v1/') && $r->header('user-api-key')[0] === 'test-key');
        $this->assertTrue(SyncLog::where('status', 'sent')->exists());
        $this->assertStringNotContainsString('test-key', SyncLog::latest('id')->first()->request);
    }

    public function test_inbound_channex_booking_creates_then_cancels_reservation_idempotently(): void
    {
        [$c] = $this->connect('channex');
        $arrival = now()->addDays(10)->toDateString();
        $departure = now()->addDays(12)->toDateString();
        $payload = ['event' => 'booking', 'payload' => [
            'ota_reservation_code' => 'BDC-998877', 'status' => 'new', 'revision_id' => 'rev-1', 'currency' => 'XAF', 'amount' => '115000',
            'arrival_date' => $arrival, 'departure_date' => $departure, 'ota_name' => 'Booking.com',
            'customer' => ['name' => 'Marie', 'surname' => 'Ngo', 'mail' => 'marie@example.cm', 'country' => 'CM'],
            'rooms' => [['room_type_id' => 'DLX-EXT', 'rate_plan_id' => 'BAR-EXT', 'occupancy' => ['adults' => 2]]],
        ]];

        $this->postJson("/api/v1/channels/inbound/{$c['uuid']}", $payload)->assertOk()->assertJsonPath('results.0.result', 'created');
        $this->postJson("/api/v1/channels/inbound/{$c['uuid']}", $payload)->assertOk()->assertJsonPath('results.0.result', 'duplicate');

        $r = Reservation::where('external_ref', 'BDC-998877')->firstOrFail();
        $this->assertSame(115_000, $r->total_amount);
        $this->assertSame('channel_manager', $r->channel);

        $payload['payload']['status'] = 'cancelled';
        $payload['payload']['revision_id'] = 'rev-2';
        $this->postJson("/api/v1/channels/inbound/{$c['uuid']}", $payload)->assertOk()->assertJsonPath('results.0.result', 'cancelled');
        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_inbound_booking_com_ota_xml_is_parsed_and_acknowledged(): void
    {
        [$c] = $this->connect('booking_com');
        $a = now()->addDays(5)->toDateString();
        $d = now()->addDays(6)->toDateString();
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_HotelResNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05" Version="1.0">
  <HotelReservations>
    <HotelReservation ResStatus="Book" CreateDateTime="2026-11-02T10:00:00">
      <RoomStays><RoomStay>
        <RoomTypes><RoomType RoomTypeCode="DLX-EXT"/></RoomTypes>
        <RatePlans><RatePlan RatePlanCode="BAR-EXT"/></RatePlans>
        <GuestCounts><GuestCount AgeQualifyingCode="10" Count="2"/></GuestCounts>
        <TimeSpan Start="$a" End="$d"/>
        <Total AmountAfterTax="57500" CurrencyCode="XAF"/>
      </RoomStay></RoomStays>
      <ResGuests><ResGuest><Profiles><ProfileInfo><Profile><Customer>
        <PersonName><GivenName>Paul</GivenName><Surname>Essomba</Surname></PersonName>
        <Email>paul@example.cm</Email>
      </Customer></Profile></ProfileInfo></Profiles></ResGuest></ResGuests>
      <ResGlobalInfo><HotelReservationIDs><HotelReservationID ResID_Type="14" ResID_Value="4455667788"/></HotelReservationIDs></ResGlobalInfo>
    </HotelReservation>
  </HotelReservations>
</OTA_HotelResNotifRQ>
XML;

        $res = $this->call('POST', "/api/v1/channels/inbound/{$c['uuid']}", [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml);
        $res->assertOk();
        $this->assertStringContainsString('<Success/>', $res->getContent());
        $this->assertStringContainsString('4455667788', $res->getContent());
        $r = Reservation::where('external_ref', '4455667788')->firstOrFail();
        $this->assertSame('booking_com', $r->channel);
        $this->assertSame(2, $r->adults);
    }

    public function test_signed_connection_rejects_bad_signature(): void
    {
        [$c] = $this->connect('channex', ['credentials' => ['api_key' => 'k', 'webhook_secret' => 'shh'], 'settings' => ['dry_run' => true]]);
        $body = json_encode(['event' => 'booking', 'payload' => []]);

        $this->call('POST', "/api/v1/channels/inbound/{$c['uuid']}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'sha256=bad'], $body)->assertStatus(401);
        $good = 'sha256='.hash_hmac('sha256', $body, 'shh');
        $this->call('POST', "/api/v1/channels/inbound/{$c['uuid']}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $good], $body)->assertOk();
    }

    public function test_expedia_and_siteminder_payload_previews(): void
    {
        [$c] = $this->connect('expedia');
        $from = now()->toDateString();
        $to = now()->addDays(2)->toDateString();
        $p = $this->getJson("/api/v1/channels/connections/{$c['id']}/preview?from=$from&to=$to")->assertOk()->json();
        $this->assertStringContainsString('<AvailRateUpdateRQ', $p['requests'][0]['body']);
        $this->assertStringContainsString('totalInventoryAvailable="3"', $p['requests'][0]['body']);

        $sm = app(TenantContext::class)->withoutScope(fn () => Connection::find($c['id']));
        $sm->forceFill(['provider' => 'siteminder'])->save();
        $p = $this->getJson("/api/v1/channels/connections/{$c['id']}/preview?from=$from&to=$to")->json();
        $this->assertStringContainsString('OTA_HotelAvailNotifRQ', $p['requests'][0]['body']);
        $this->assertStringContainsString('wsse:Security', $p['requests'][0]['body']);
    }

    public function test_tripadvisor_metasearch_price_pull(): void
    {
        [$c] = $this->connect('tripadvisor');
        $in = now()->addDays(4)->toDateString();
        $out = now()->addDays(6)->toDateString();

        $this->getJson("/api/v1/public/meta/{$c['uuid']}/availability?checkin=$in&checkout=$out&adults=2")
            ->assertOk()->assertJsonPath('offers.0.price', 115_000)->assertJsonPath('offers.0.room_type_code', 'DLX-EXT');
    }

    public function test_secrets_are_masked_in_listing_and_preview(): void
    {
        [$c] = $this->connect('booking_com', ['credentials' => ['username' => 'machine', 'password' => 's3cr3t-pw']]);

        $fields = collect($this->getJson('/api/v1/channels/providers')->assertOk()->json())->firstWhere('code', 'booking_com')['credential_fields'];
        $this->assertContains('password', $fields);

        $list = $this->getJson('/api/v1/channels/connections')->assertOk();
        $this->assertStringNotContainsString('s3cr3t-pw', $list->getContent());
        $this->assertSame('********', $list->json('0.credential_keys.password'));

        $preview = $this->getJson("/api/v1/channels/connections/{$c['id']}/preview?from=".now()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk();
        $this->assertStringNotContainsString(base64_encode('machine:s3cr3t-pw'), $preview->getContent());
    }
}

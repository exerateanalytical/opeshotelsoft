<?php

namespace Tests\Feature\ServiceRequests;

use App\Modules\ServiceRequests\Application\ServiceRequestService;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VoiceFeedTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function make(array $over = []): ServiceRequest
    {
        return app(ServiceRequestService::class)->create($over + [
            'hotel_id' => $this->hotel->id, 'room_number' => '204', 'category' => 'housekeeping', 'items' => ['towels'], 'source' => 'reception',
        ]);
    }

    public function test_feed_without_cursor_only_returns_the_starting_cursor(): void
    {
        $this->make();
        $r = $this->getJson('/api/v1/service-requests/feed')->assertOk();
        $this->assertSame([], $r->json('events'));
        $this->assertGreaterThan(0, $r->json('cursor'));
    }

    public function test_feed_returns_events_after_since_with_spoken_text_in_the_users_language(): void
    {
        $cursor = $this->getJson('/api/v1/service-requests/feed')->json('cursor');
        $this->make();
        $e = $this->getJson("/api/v1/service-requests/feed?since=$cursor&locale=fr")->assertOk()->json();
        $this->assertCount(1, $e['events']);
        $this->assertSame('Chambre deux cent quatre. Demande de serviettes.', $e['events'][0]['speech']);
        $this->assertSame('Chambre 204. Demande de serviettes.', $e['events'][0]['text']);
        $this->assertSame('new', $e['events'][0]['status']);
        $this->assertSame($e['events'][0]['id'], $e['cursor']);
        $this->assertCount(1, $e['pending']);

        $en = $this->getJson("/api/v1/service-requests/feed?since=$cursor&locale=en")->json('events.0.speech');
        $this->assertSame('Room two hundred four. Towels requested.', $en);

        $this->user->update(['locale' => 'en']);
        $this->assertSame('Room two hundred four. Towels requested.', $this->getJson("/api/v1/service-requests/feed?since=$cursor")->json('events.0.speech'));

        // nothing new after the cursor
        $this->assertSame([], $this->getJson("/api/v1/service-requests/feed?since={$e['cursor']}")->json('events'));
    }

    public function test_feed_only_contains_the_users_departments(): void
    {
        $cursor = $this->getJson('/api/v1/service-requests/feed')->json('cursor');
        $this->make();
        $this->make(['category' => 'maintenance', 'room_number' => '205', 'items' => ['ac']]);
        $this->make(['category' => 'room_service', 'room_number' => '206', 'items' => ['meal']]);

        $attendant = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($attendant, $this->hotel);
        $events = $this->getJson("/api/v1/service-requests/feed?since=$cursor")->assertOk()->json('events');
        $this->assertSame(['housekeeping'], collect($events)->pluck('department')->unique()->values()->all());

        // the user's own choice wins over the role default
        $this->putJson('/api/v1/service-requests/voice-settings', ['departments' => ['maintenance', 'fnb']])->assertOk();
        $events = $this->getJson("/api/v1/service-requests/feed?since=$cursor")->json('events');
        $this->assertEqualsCanonicalizing(['maintenance', 'fnb'], collect($events)->pluck('department')->all());

        $this->putJson('/api/v1/service-requests/voice-settings', ['departments' => []])->assertOk();
        $this->assertSame([], $this->getJson("/api/v1/service-requests/feed?since=$cursor")->json('events'));
    }

    public function test_feed_is_tenant_isolated(): void
    {
        $cursor = $this->getJson('/api/v1/service-requests/feed')->json('cursor');
        [, $other] = $this->makeHotel('Other Group', 'OTH');
        app(ServiceRequestService::class)->create(['hotel_id' => $other->id, 'room_number' => '204', 'category' => 'housekeeping', 'items' => ['towels'], 'source' => 'reception']);
        $this->actAs($this->user, $this->hotel);
        $r = $this->getJson("/api/v1/service-requests/feed?since=0")->assertOk();
        $this->assertSame([], $r->json('events'));
        $this->assertSame([], $r->json('pending'));
        $this->assertSame(0, $this->getJson('/api/v1/service-requests')->json('data') === [] ? 0 : 1);

        // a user of the other group cannot see this hotel's events even by sending its id
        $outsider = $this->makeUser($other->group, 'general-manager', allHotels: true);
        $this->make();
        $this->actAs($outsider, $this->hotel);
        $this->getJson("/api/v1/service-requests/feed?since=$cursor")->assertStatus(403);
    }

    public function test_events_older_than_the_window_are_not_replayed(): void
    {
        $this->make();
        Carbon::setTestNow(now()->addMinutes(11));
        $r = $this->getJson('/api/v1/service-requests/feed?since=0')->assertOk();
        $this->assertSame([], $r->json('events'));
        $this->assertCount(1, $r->json('pending')); // still unacknowledged, so repeat can pick it up
    }

    public function test_acknowledging_removes_the_request_from_pending(): void
    {
        $r = $this->make();
        $this->assertCount(1, $this->getJson('/api/v1/service-requests/feed')->json('pending'));
        $this->postJson("/api/v1/service-requests/{$r->id}/acknowledge")->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/service-requests/feed')->json('pending'));
        $cursor = 0;
        $this->assertSame('acknowledged', $this->getJson("/api/v1/service-requests/feed?since=$cursor")->json('events.0.status'));
    }

    public function test_voice_settings_are_stored_per_user_with_validation(): void
    {
        $d = $this->getJson('/api/v1/service-requests/voice-settings')->assertOk();
        $this->assertSame(0, $d->json('settings.repeat_seconds'));
        $this->assertTrue($d->json('settings.enabled'));

        $this->putJson('/api/v1/service-requests/voice-settings', [
            'volume' => 0.4, 'rate' => 1.2, 'voice' => 'Microsoft Hortense', 'repeat_seconds' => 60,
            'quiet_enabled' => true, 'quiet_start' => '23:00', 'quiet_end' => '05:30', 'chime' => false,
        ])->assertOk()->assertJsonPath('settings.repeat_seconds', 60)->assertJsonPath('settings.voice', 'Microsoft Hortense');
        $this->putJson('/api/v1/service-requests/voice-settings', ['volume' => 3])->assertStatus(422);
        $this->putJson('/api/v1/service-requests/voice-settings', ['repeat_seconds' => 7])->assertStatus(422);
        $this->putJson('/api/v1/service-requests/voice-settings', ['quiet_start' => '25:00'])->assertStatus(422);
        $this->putJson('/api/v1/service-requests/voice-settings', ['departments' => ['bar']])->assertStatus(422);

        $other = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($other, $this->hotel);
        $this->assertSame(0.4 === $this->getJson('/api/v1/service-requests/voice-settings')->json('settings.volume') ? 'leak' : 'ok', 'ok');
        $this->actAs($this->user, $this->hotel);
        $this->assertEquals(0.4, $this->getJson('/api/v1/service-requests/voice-settings')->json('settings.volume'));
    }
}

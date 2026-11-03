<?php

namespace Tests\Feature\Notifications;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Application\ChannelManager;
use App\Modules\Notifications\Application\Drivers\LogDriver;
use App\Modules\Notifications\Application\Drivers\WhatsAppCloudDriver;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    private function book(Guest $guest, array $over = []): array
    {
        [$type, $plan] = $this->seedInventory();

        return $this->postJson('/api/v1/reservations', $over + [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(2)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
        ])->assertCreated()->json();
    }

    public function test_reservation_created_sends_confirmation_in_guest_language(): void
    {
        $guest = Guest::create(['first_name' => 'Mary', 'last_name' => 'Smith', 'email' => 'mary@test.cm', 'phone' => '+237690000001', 'language' => 'en']);
        $r = $this->book($guest);

        $email = OutboxMessage::where(['event' => 'reservation.created', 'channel' => 'email'])->firstOrFail();
        $this->assertSame('sent', $email->status);
        $this->assertSame('en', $email->locale);
        $this->assertSame('mary@test.cm', $email->recipient);
        $this->assertStringContainsString("Booking confirmation {$r['number']}", $email->subject);
        $this->assertStringContainsString('Hello Mary Smith', $email->body);
        $this->assertSame(1, OutboxMessage::where(['event' => 'reservation.created', 'channel' => 'sms'])->count());
        $this->assertSame(0, OutboxMessage::where(['event' => 'reservation.created', 'channel' => 'whatsapp'])->count(), 'WhatsApp is opt-in');

        $this->postJson("/api/v1/reservations/{$r['id']}/cancel", ['reason' => 'test'])->assertSuccessful();
        $this->assertSame('en', OutboxMessage::where('event', 'reservation.cancelled')->where('channel', 'email')->value('locale'));
    }

    public function test_french_is_default_language(): void
    {
        $guest = Guest::create(['first_name' => 'Paul', 'last_name' => 'Etoa', 'email' => 'paul@test.cm']);
        $this->book($guest);
        $m = OutboxMessage::where(['event' => 'reservation.created', 'channel' => 'email'])->firstOrFail();
        $this->assertSame('fr', $m->locale);
        $this->assertStringContainsString('Confirmation de réservation', $m->subject);
    }

    public function test_uses_log_driver_without_credentials(): void
    {
        $this->assertInstanceOf(LogDriver::class, app(ChannelManager::class)->driver('sms'));
        $this->assertInstanceOf(LogDriver::class, app(ChannelManager::class)->driver('whatsapp'));
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'phone' => '+237690000002']);
        NotificationPreference::create(['guest_id' => $guest->id, 'whatsapp' => true]);
        $this->book($guest);
        foreach (OutboxMessage::where('event', 'reservation.created')->get() as $m) {
            $this->assertSame('log', $m->driver);
            $this->assertSame('sent', $m->status);
            $this->assertStringStartsWith('log-', $m->provider_id);
        }
        $this->assertEqualsCanonicalizing(['sms', 'whatsapp'], OutboxMessage::where('event', 'reservation.created')->pluck('channel')->all());
    }

    public function test_marketing_requires_consent_but_transactional_always_sends(): void
    {
        $guest = Guest::create(['first_name' => 'No', 'last_name' => 'Consent', 'email' => 'nc@test.cm', 'marketing_consent' => false]);
        NotificationPreference::create(['guest_id' => $guest->id, 'email' => false, 'sms' => false]);

        $out = app(Notifier::class)->notify('marketing.offer', ['guest_id' => $guest->id], ['title' => 'Promo', 'message' => '-20%']);
        $this->assertSame('skipped', $out[0]->status);

        $out = app(Notifier::class)->notify('reservation.created', ['guest_id' => $guest->id], ['number' => 'X1']);
        $this->assertCount(1, $out, 'transactional falls back to a reachable channel');
        $this->assertSame('sent', $out[0]->fresh()->status);

        $guest->update(['marketing_consent' => true]);
        NotificationPreference::where('guest_id', $guest->id)->update(['email' => true]);
        $out = app(Notifier::class)->notify('marketing.offer', ['guest_id' => $guest->id], ['title' => 'Promo', 'message' => '-20%']);
        $this->assertSame('sent', $out[0]->fresh()->status);
    }

    public function test_failed_sends_are_retried_with_backoff(): void
    {
        config(['notifications.whatsapp.token' => 'tok', 'notifications.whatsapp.phone_number_id' => '123']);
        $this->assertInstanceOf(WhatsAppCloudDriver::class, app(ChannelManager::class)->driver('whatsapp'));
        Http::fakeSequence()->push(['error' => 'down'], 500)->push(['messages' => [['id' => 'wamid.OK']]], 200);

        $guest = Guest::create(['first_name' => 'W', 'last_name' => 'A', 'phone' => '+237690000003']);
        NotificationPreference::create(['guest_id' => $guest->id, 'sms' => false, 'whatsapp' => true]);
        [$m] = app(Notifier::class)->notify('reservation.created', ['guest_id' => $guest->id], ['number' => 'R1']);
        $m->refresh();
        $this->assertSame('retrying', $m->status);
        $this->assertSame(1, $m->attempts);
        $this->assertNotNull($m->error);
        $this->assertTrue($m->next_attempt_at->gt(now()->addSeconds(30)));

        $this->artisan('notifications:retry')->assertSuccessful();
        $this->assertSame('retrying', $m->fresh()->status, 'not due yet');

        $this->travel(2)->minutes();
        $this->artisan('notifications:retry')->assertSuccessful();
        $m->refresh();
        $this->assertSame('sent', $m->status);
        $this->assertSame(2, $m->attempts);
        $this->assertSame('wamid.OK', $m->provider_id);
    }

    public function test_message_fails_after_max_attempts_and_can_be_resent(): void
    {
        config(['notifications.sms.driver' => 'http', 'notifications.sms.url' => 'https://sms.test/send', 'notifications.sms.token' => 't']);
        $up = false;
        Http::fake(function () use (&$up) {
            return $up ? Http::response(['id' => 'sms-1'], 200) : Http::response('no', 500);
        });
        $guest = Guest::create(['first_name' => 'S', 'last_name' => 'M', 'phone' => '+237690000004']);
        [$m] = app(Notifier::class)->notify('reservation.cancelled', ['guest_id' => $guest->id], ['number' => 'R2']);
        for ($i = 0; $i < 5; $i++) {
            $this->travel(4)->hours();
            $this->artisan('notifications:retry');
        }
        $this->assertSame('failed', $m->fresh()->status);
        $this->assertSame(5, $m->fresh()->attempts);

        $up = true;
        $this->postJson("/api/v1/notifications/outbox/{$m->id}/resend")->assertOk();
        $this->assertSame('sent', $m->fresh()->status);
        $this->getJson('/api/v1/notifications/outbox?status=sent')->assertOk()->assertJsonPath('data.0.id', $m->id);
    }

    public function test_pre_arrival_command_sends_once(): void
    {
        $guest = Guest::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa@test.cm']);
        $this->book($guest, ['arrival' => now()->addDay()->toDateString(), 'departure' => now()->addDays(3)->toDateString()]);
        $this->artisan('notifications:pre-arrival')->assertSuccessful();
        $this->artisan('notifications:pre-arrival')->assertSuccessful();
        $this->assertSame(1, OutboxMessage::where(['event' => 'reservation.pre_arrival', 'channel' => 'email'])->count());
    }

    public function test_templates_seeded_editable_and_previewable(): void
    {
        $res = $this->getJson('/api/v1/notifications/templates?event=reservation.created&channel=email')->assertOk()->json();
        $this->assertCount(2, $res['templates']);
        $all = collect($this->getJson('/api/v1/notifications/templates')->json('templates'));
        foreach (['reservation.created', 'reservation.cancelled', 'reservation.pre_arrival', 'invoice.issued', 'guest_portal.otp', 'staff.password_reset'] as $e) {
            $this->assertEqualsCanonicalizing(['fr', 'en'], $all->where('event', $e)->where('channel', 'email')->pluck('locale')->all(), $e);
        }
        $fr = collect($res['templates'])->firstWhere('locale', 'fr');
        $this->putJson("/api/v1/notifications/templates/{$fr['id']}", ['subject' => 'Merci {{guest_name}}', 'body' => 'Séjour {{number}}'])->assertOk();

        $this->postJson('/api/v1/notifications/templates/preview', ['template_id' => $fr['id'], 'variables' => ['number' => 'N9']])
            ->assertOk()->assertJsonPath('subject', 'Merci Jean Mballa')->assertJsonPath('body', 'Séjour N9');

        $guest = Guest::create(['first_name' => 'Z', 'last_name' => 'Y', 'email' => 'z@test.cm']);
        $this->book($guest);
        $this->assertSame('Merci Z Y', OutboxMessage::where(['event' => 'reservation.created', 'channel' => 'email'])->value('subject'));
    }

    public function test_permissions_and_preferences(): void
    {
        $guest = Guest::create(['first_name' => 'Q', 'last_name' => 'R', 'email' => 'q@test.cm']);
        $this->putJson("/api/v1/notifications/preferences/{$guest->id}", ['whatsapp' => true])->assertOk()->assertJsonPath('whatsapp', true)
            ->assertJsonPath('marketing_consent', false);

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/notifications/templates')->assertForbidden();
        $this->getJson('/api/v1/notifications/outbox')->assertForbidden();

        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/notifications/outbox')->assertOk();
        $this->putJson('/api/v1/notifications/templates/1', ['body' => 'x'])->assertForbidden();
    }
}

<?php

namespace Tests\Feature\ServiceRequests;

use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\ServiceRequests\Application\EscalationService;
use App\Modules\ServiceRequests\Application\ServiceRequestService;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use App\Modules\ServiceRequests\Domain\VoiceEvent;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceRequestsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): ServiceRequestService
    {
        return app(ServiceRequestService::class);
    }

    private function make(array $over = []): ServiceRequest
    {
        return $this->svc()->create($over + [
            'hotel_id' => $this->hotel->id, 'room_number' => '204', 'category' => 'housekeeping', 'items' => ['towels'], 'source' => 'reception',
        ]);
    }

    public function test_create_routes_to_department_sets_sla_and_records_event(): void
    {
        $r = $this->make();
        $this->assertSame('new', $r->status);
        $this->assertSame('housekeeping', $r->department);
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($r->due_at, false), 5);
        $ev = StoredEvent::where('type', 'servicerequests.created')->sole();
        $this->assertEqualsCanonicalizing(['id', 'hotel_id', 'room_number', 'category', 'department', 'items', 'note', 'priority', 'source'], array_keys($ev->payload));
        $this->assertSame('204', $ev->payload['room_number']);
        $this->assertSame(1, VoiceEvent::where('kind', 'request')->count());

        $this->assertSame('maintenance', $this->make(['category' => 'maintenance', 'room_number' => '205'])->department);
        $this->assertSame('fnb', $this->make(['category' => 'room_service', 'room_number' => '206'])->department);
        $this->assertSame('front_office', $this->make(['category' => 'reception', 'room_number' => '207'])->department);
        $this->assertSame('housekeeping', $this->make(['category' => 'laundry', 'room_number' => '208'])->department);
    }

    public function test_identical_open_request_within_five_minutes_is_folded(): void
    {
        $a = $this->make();
        $b = $this->make();
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ServiceRequest::count());
        $this->assertSame(1, StoredEvent::where('type', 'servicerequests.created')->count());

        $this->make(['items' => ['pillows']]); // different items
        $this->make(['room_number' => '205']); // different room
        $this->assertSame(3, ServiceRequest::count());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertNotSame($a->id, $this->make()->id);
        $this->assertSame(4, ServiceRequest::count());
    }

    public function test_done_request_does_not_block_a_new_identical_one(): void
    {
        $a = $this->make();
        $this->svc()->complete($a, 'Delivered');
        $this->assertNotSame($a->id, $this->make()->id);
    }

    public function test_hotel_tunable_sla_applies(): void
    {
        $this->putJson('/api/v1/service-requests/settings', ['sla' => ['housekeeping' => 10]])->assertOk()->assertJsonPath('sla.housekeeping', 10)->assertJsonPath('sla.maintenance', 60);
        $r = $this->make();
        $this->assertEqualsWithDelta(10 * 60, now()->diffInSeconds($r->due_at, false), 5);
        $this->putJson('/api/v1/service-requests/settings', ['sla' => ['bogus' => 10]])->assertStatus(422);
        // urgent halves the delay
        $u = $this->make(['room_number' => '300', 'priority' => 'urgent']);
        $this->assertEqualsWithDelta(5 * 60, now()->diffInSeconds($u->due_at, false), 5);
    }

    public function test_status_flow_and_assignment(): void
    {
        $this->seedInventory(rooms: 2);
        $id = $this->postJson('/api/v1/service-requests', ['room_number' => '101', 'category' => 'housekeeping', 'items' => ['towels'], 'source' => 'room_phone'])->assertCreated()->json('id');
        $this->postJson("/api/v1/service-requests/$id/acknowledge")->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->postJson("/api/v1/service-requests/$id/acknowledge")->assertStatus(422);
        $staff = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->postJson("/api/v1/service-requests/$id/assign", ['user_id' => $staff->id])->assertOk()->assertJsonPath('assignee.id', $staff->id);
        $this->postJson("/api/v1/service-requests/$id/start")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson("/api/v1/service-requests/$id/complete", ['note' => 'Left at door'])->assertOk()->assertJsonPath('status', 'done');
        $this->postJson("/api/v1/service-requests/$id/cancel")->assertStatus(422);
        $this->assertSame(1, StoredEvent::where('type', 'servicerequests.completed')->count());
        $this->getJson('/api/v1/service-requests?status=done')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/service-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_entry_requires_an_existing_room_and_valid_category(): void
    {
        $this->postJson('/api/v1/service-requests', ['room_number' => '999', 'category' => 'housekeeping'])->assertStatus(422);
        $this->seedInventory(rooms: 2);
        $this->postJson('/api/v1/service-requests', ['room_number' => '101', 'category' => 'nope'])->assertStatus(422);
        $this->postJson('/api/v1/service-requests', ['room_number' => '101', 'category' => 'maintenance', 'note' => 'AC leaks', 'priority' => 'high'])
            ->assertCreated()->assertJsonPath('source', 'reception')->assertJsonPath('department', 'maintenance');
        $this->postJson('/api/v1/service-requests', ['room_number' => '101', 'category' => 'maintenance', 'source' => 'member_portal'])->assertStatus(422);
    }

    public function test_member_portal_requests_need_an_in_house_guest(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2);
        $base = ['hotel_id' => $this->hotel->id, 'room_number' => '101', 'category' => 'housekeeping', 'items' => ['towels'], 'source' => 'member_portal', 'guest_id' => $guest->id];
        try {
            $this->svc()->create($base);
            $this->fail('Expected not-in-house');
        } catch (DomainException $e) {
            $this->assertSame('not-in-house', $e->problem);
        }

        $guest->update(['id_type' => 'passport', 'id_number' => 'CM1', 'nationality' => 'CM']);
        $res = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDays(2)->toDateString(),
        ]);
        $room = Room::where('number', '101')->first();
        $this->postJson("/api/v1/reservations/{$res->id}/check-in", ['room_id' => $room->id])->assertOk();

        $r = $this->svc()->create($base);
        $this->assertSame($res->id, $r->reservation_id);
        $this->assertSame('member_portal', $r->source);

        // another room is refused
        try {
            $this->svc()->create(['room_number' => '102'] + $base);
            $this->fail('Expected not-in-house');
        } catch (DomainException $e) {
            $this->assertSame('not-in-house', $e->problem);
        }
    }

    public function test_service_runs_in_the_requested_hotels_context_without_a_staff_tenant(): void
    {
        [, $other] = $this->makeHotel('Other Group', 'OTH');
        app(\App\Platform\Tenancy\TenantContext::class)->set(null, null, null, null);
        $r = $this->svc()->create(['hotel_id' => $other->id, 'room_number' => '1', 'category' => 'reception', 'source' => 'whatsapp']);
        $this->assertSame($other->id, $r->hotel_id);
        $this->assertNull(app(\App\Platform\Tenancy\TenantContext::class)->hotelId());
    }

    public function test_escalation_at_half_sla_then_manager_alert_past_sla(): void
    {
        $r = $this->make(); // 30 min SLA
        $this->assertSame(['late' => 0, 'overdue' => 0], app(EscalationService::class)->run());

        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(['late' => 1, 'overdue' => 0], app(EscalationService::class)->run());
        $this->assertSame(['late' => 0, 'overdue' => 0], app(EscalationService::class)->run()); // once
        $late = VoiceEvent::where('kind', 'late')->sole();
        $this->assertSame($r->id, $late->service_request_id);

        Carbon::setTestNow(now()->addMinutes(20));
        $this->assertSame(['late' => 0, 'overdue' => 1], app(EscalationService::class)->run());
        $over = VoiceEvent::where('kind', 'overdue')->sole();
        $this->assertGreaterThanOrEqual(5, $over->payload['minutes_late']);
        $this->assertGreaterThanOrEqual(1, OutboxMessage::where('event', EscalationService::EVENT)->where('recipient', $this->user->email)->count());
        $this->assertSame(['late' => 0, 'overdue' => 0], app(EscalationService::class)->run());
    }

    public function test_acknowledged_requests_are_not_announced_late_but_still_alert_when_overdue(): void
    {
        $r = $this->make();
        $this->svc()->acknowledge($r);
        Carbon::setTestNow(now()->addMinutes(20));
        $this->assertSame(['late' => 0, 'overdue' => 0], app(EscalationService::class)->run());
        Carbon::setTestNow(now()->addMinutes(20));
        $this->assertSame(['late' => 0, 'overdue' => 1], app(EscalationService::class)->run());
        $this->svc()->complete($r->fresh());
        $this->make(['room_number' => '300']);
        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(1, app(EscalationService::class)->run()['overdue']); // only the open one
    }

    public function test_command_runs_for_every_active_hotel(): void
    {
        $this->make();
        Carbon::setTestNow(now()->addMinutes(40));
        $this->artisan('servicerequests:escalate')->assertSuccessful();
        $this->assertSame(1, VoiceEvent::withoutGlobalScopes()->where('kind', 'overdue')->count());
        $this->assertNotNull(ServiceRequest::withoutGlobalScopes()->first()->overdue_at);
    }

    public function test_guest_message_event_raises_front_office_voice_alert(): void
    {
        $bus = app(EventBus::class);
        $e = $bus->record('messaging.guest_message', ['hotel_id' => $this->hotel->id, 'conversation_id' => 7, 'guest_id' => 1, 'room_number' => '204', 'text' => 'Bonjour, une question']);
        $bus->dispatch($e);
        $v = VoiceEvent::where('kind', 'message')->sole();
        $this->assertSame('front_office', $v->department);
        $this->assertSame('204', $v->room_number);

        // no room (not in house) or switched off: nothing spoken
        $bus->dispatch($bus->record('messaging.guest_message', ['hotel_id' => $this->hotel->id, 'text' => 'hello']));
        $this->assertSame(1, VoiceEvent::where('kind', 'message')->count());
        $this->putJson('/api/v1/service-requests/settings', ['voice_messages' => false])->assertOk();
        $bus->dispatch($bus->record('messaging.guest_message', ['hotel_id' => $this->hotel->id, 'room_number' => '205', 'text' => 'hello']));
        $this->assertSame(1, VoiceEvent::where('kind', 'message')->count());

        $feed = $this->getJson('/api/v1/service-requests/feed?since=0&locale=fr')->assertOk()->json('events');
        $this->assertSame('Message de la chambre deux cent quatre.', $feed[0]['speech']);
    }

    public function test_board_list_filters_by_department(): void
    {
        $this->make();
        $this->make(['category' => 'maintenance', 'room_number' => '205']);
        $this->getJson('/api/v1/service-requests?department=maintenance')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.room_number', '205');
        $this->getJson('/api/v1/service-requests')->assertOk()->assertJsonCount(2, 'data');
        $cat = $this->getJson('/api/v1/service-requests/catalog')->assertOk();
        $this->assertCount(7, $cat->json('categories'));
    }
}

<?php

namespace Tests\Feature\People;

use App\Platform\Events\EventBus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DisciplineFeedbackTest extends PeopleTestCase
{
    public function test_manual_feedback_and_review_event_feed_staff_summary(): void
    {
        $emp = $this->employee();
        $this->postJson('/api/v1/people/feedback', ['employee_id' => $emp->id, 'rating' => 5, 'comment' => 'Très accueillant'])
            ->assertCreated()->assertJsonPath('sentiment', 'positive')->assertJsonPath('source', 'manual');

        $payload = ['id' => 77, 'rating' => 2, 'comment' => 'Service lent', 'guest_id' => null, 'staff_employee_ids' => [$emp->id]];
        $bus = app(EventBus::class);
        $bus->dispatch($bus->record('guests.review_received', $payload, 'review', 77));
        $bus->dispatch($bus->record('guests.review_received', $payload, 'review', 77)); // replay is idempotent

        $this->getJson("/api/v1/people/employees/{$emp->id}/feedback-summary")->assertOk()
            ->assertJsonPath('count', 2)->assertJsonPath('positive', 1)->assertJsonPath('negative', 1);
        $this->assertSame('review', $this->getJson('/api/v1/people/feedback?sentiment=negative')->json('data.0.source'));
    }

    public function test_sanctions_with_documents_and_employee_acknowledgement(): void
    {
        Storage::fake('local');
        $user = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $emp = $this->employee([], $user);
        $other = $this->employee();

        $this->postJson('/api/v1/people/sanctions', ['employee_id' => $emp->id, 'kind' => 'suspension', 'incident_date' => now()->toDateString(), 'reason' => 'Absence'])
            ->assertStatus(422);
        $this->postJson('/api/v1/people/sanctions', ['employee_id' => $emp->id, 'kind' => 'suspension', 'incident_date' => now()->toDateString(), 'reason' => 'Absence',
            'suspension_start' => now()->addDay()->toDateString(), 'suspension_end' => now()->addDays(12)->toDateString()])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/people-suspension-too-long');
        $s = $this->postJson('/api/v1/people/sanctions', ['employee_id' => $emp->id, 'kind' => 'written_warning', 'incident_date' => now()->toDateString(), 'reason' => 'Retards répétés'])
            ->assertCreated()->json();
        $this->postJson('/api/v1/people/sanctions', ['employee_id' => $other->id, 'kind' => 'verbal_warning', 'incident_date' => now()->toDateString(), 'reason' => 'Tenue'])->assertCreated();
        $this->post("/api/v1/people/sanctions/{$s['id']}/documents", ['file' => UploadedFile::fake()->create('lettre.pdf', 40, 'application/pdf'), 'title' => 'Lettre'])->assertCreated();

        // The employee sees only their own sanctions and acknowledges receipt.
        $this->actAs($user, $this->hotel);
        $mine = $this->getJson('/api/v1/people/sanctions')->assertOk()->json();
        $this->assertSame([$s['id']], array_column($mine, 'id'));
        $this->postJson('/api/v1/people/sanctions', ['employee_id' => $other->id, 'kind' => 'verbal_warning', 'incident_date' => now()->toDateString(), 'reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/people/sanctions/{$s['id']}/acknowledge", ['note' => 'Reçu'])->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->postJson("/api/v1/people/sanctions/{$s['id']}/acknowledge")->assertStatus(422);
        $doc = $this->getJson("/api/v1/people/sanctions/{$s['id']}")->assertOk()->json('documents.0.id');
        $this->get("/api/v1/people/sanctions/{$s['id']}/documents/$doc")->assertOk();
    }
}

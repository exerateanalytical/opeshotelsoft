<?php

namespace Tests\Feature\People;

use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\People\Domain\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TasksTest extends PeopleTestCase
{
    public function test_task_to_person_notifies_and_needs_checklist_and_photo_to_complete(): void
    {
        Storage::fake('local');
        $user = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $emp = $this->employee([], $user);

        $task = $this->postJson('/api/v1/people/tasks', [
            'title' => 'Nettoyer la piscine', 'assignee_employee_id' => $emp->id, 'due_at' => now()->addDay()->toIso8601String(),
            'priority' => 'high', 'checklist' => ['Filtre', 'Chlore'], 'requires_photo' => true,
        ])->assertCreated()->assertJsonPath('checklist.1.done', false)->json();

        $msg = OutboxMessage::where('event', 'people.task_assigned')->where('ref_id', $task['id'])->first();
        $this->assertNotNull($msg);
        $this->assertSame($emp->email, $msg->recipient);
        $this->assertStringContainsString('Nettoyer la piscine', $msg->subject);

        // The assignee works the task without any people.* permission.
        $this->actAs($user, $this->hotel);
        $this->assertCount(1, $this->getJson('/api/v1/people/tasks/mine')->assertOk()->json());
        $this->getJson('/api/v1/people/tasks')->assertForbidden();
        $this->postJson("/api/v1/people/tasks/{$task['id']}/checklist/0", ['done' => true])->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson("/api/v1/people/tasks/{$task['id']}/complete")->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/people-task-checklist-open');
        $this->postJson("/api/v1/people/tasks/{$task['id']}/checklist/1", ['done' => true])->assertOk();
        $this->postJson("/api/v1/people/tasks/{$task['id']}/complete")->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/people-task-photo-required');
        $photo = $this->post("/api/v1/people/tasks/{$task['id']}/photos", ['photo' => UploadedFile::fake()->image('proof.jpg')])->assertCreated()->json();
        Storage::disk('local')->assertExists($photo['path']);
        $this->postJson("/api/v1/people/tasks/{$task['id']}/complete", ['note' => 'OK'])->assertOk()->assertJsonPath('status', 'done');
    }

    public function test_department_task_reaches_members_and_others_cannot_act(): void
    {
        $dept = $this->department();
        $a = $this->employee(['department_id' => $dept->id]);
        $b = $this->employee(['department_id' => $dept->id]);
        $outsiderUser = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->employee([], $outsiderUser);

        $task = $this->postJson('/api/v1/people/tasks', ['title' => 'Inventaire linge', 'department_id' => $dept->id])->assertCreated()->json();
        $this->assertEqualsCanonicalizing([$a->email, $b->email], OutboxMessage::where('ref_id', $task['id'])->where('channel', 'email')->pluck('recipient')->all());

        $this->postJson('/api/v1/people/tasks', ['title' => 'Sans destinataire'])->assertStatus(422);

        $this->actAs($outsiderUser, $this->hotel);
        $this->postJson("/api/v1/people/tasks/{$task['id']}/complete")->assertForbidden();
        $this->postJson('/api/v1/people/tasks', ['title' => 'X', 'department_id' => $dept->id])->assertForbidden();
    }

    public function test_overdue_view_lists_only_late_open_tasks(): void
    {
        $emp = $this->employee();
        $late = $this->postJson('/api/v1/people/tasks', ['title' => 'Late', 'assignee_employee_id' => $emp->id, 'due_at' => now()->subHour()->toIso8601String()])->json();
        $this->postJson('/api/v1/people/tasks', ['title' => 'Future', 'assignee_employee_id' => $emp->id, 'due_at' => now()->addDay()->toIso8601String()]);
        $done = $this->postJson('/api/v1/people/tasks', ['title' => 'Done late', 'assignee_employee_id' => $emp->id, 'due_at' => now()->subDay()->toIso8601String()])->json();
        $this->postJson("/api/v1/people/tasks/{$done['id']}/complete")->assertOk();

        $rows = $this->getJson('/api/v1/people/tasks/overdue')->assertOk()->json();
        $this->assertSame([$late['id']], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['overdue']);
        $this->assertSame($emp->full_name, $rows[0]['assignee_employee']['name']);
    }

    public function test_tasks_are_isolated_per_hotel(): void
    {
        $emp = $this->employee();
        $this->postJson('/api/v1/people/tasks', ['title' => 'Akwa only', 'assignee_employee_id' => $emp->id])->assertCreated();
        [, $other] = $this->makeHotel('Other', 'OTH', $this->group);
        $this->provision($other);
        $this->actAs($this->user, $other);
        $this->assertSame(0, $this->getJson('/api/v1/people/tasks')->assertOk()->json('total'));
        $this->getJson('/api/v1/people/tasks/'.Task::withoutGlobalScopes()->value('id'))->assertNotFound();
        // An employee of another hotel cannot be assigned.
        $this->postJson('/api/v1/people/tasks', ['title' => 'X', 'assignee_employee_id' => $emp->id])->assertStatus(422);
    }
}

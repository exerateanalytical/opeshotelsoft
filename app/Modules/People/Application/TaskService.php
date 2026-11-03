<?php

namespace App\Modules\People\Application;

use App\Modules\HR\Domain\Employee;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\People\Domain\Task;
use App\Modules\People\Domain\TaskPhoto;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** General tasks for a person or a department: checklist, photo proof, overdue view, notifications. */
class TaskService
{
    public const EVENT_ASSIGNED = 'people.task_assigned';

    private const TEMPLATES = [
        'fr' => ['Nouvelle tâche : {{task_title}}', "Bonjour {{employee_name}},\nUne tâche vous est assignée : {{task_title}}.\nÉchéance : {{due}}. Priorité : {{priority}}.\n{{hotel_name}}"],
        'en' => ['New task: {{task_title}}', "Hello {{employee_name}},\nA task was assigned to you: {{task_title}}.\nDue: {{due}}. Priority: {{priority}}.\n{{hotel_name}}"],
    ];

    public function __construct(private EventBus $bus, private TenantContext $ctx, private Notifier $notifier, private EmployeeDirectory $people) {}

    public function create(array $data): Task
    {
        if (empty($data['assignee_employee_id']) && empty($data['department_id'])) {
            throw new DomainException('Assign the task to a person or a department.', 'people-task-assignee');
        }
        if (! empty($data['assignee_employee_id'])) {
            $this->people->get((int) $data['assignee_employee_id']);
        }
        $data['checklist'] = collect($data['checklist'] ?? [])->map(fn ($i) => ['label' => is_array($i) ? $i['label'] : (string) $i, 'done' => false])->values()->all();

        return DB::transaction(function () use ($data) {
            $task = Task::create($data + ['status' => 'open', 'created_by' => $this->ctx->actor()?->id]);
            $this->bus->record('people.task_created', ['id' => $task->id, 'assignee_employee_id' => $task->assignee_employee_id, 'department_id' => $task->department_id], 'people_task', $task->id);
            $this->notifyAssignees($task);

            return $task;
        });
    }

    public function reassign(Task $task, ?int $employeeId, ?int $departmentId): Task
    {
        $this->assertOpen($task);
        if (! $employeeId && ! $departmentId) {
            throw new DomainException('Assign the task to a person or a department.', 'people-task-assignee');
        }
        $task->update(['assignee_employee_id' => $employeeId, 'department_id' => $departmentId]);
        $this->notifyAssignees($task);

        return $task;
    }

    public function check(Task $task, int $index, bool $done): Task
    {
        $this->assertOpen($task);
        $list = $task->checklist;
        if (! isset($list[$index])) {
            throw new DomainException('Unknown checklist item.', 'people-task-checklist');
        }
        $list[$index]['done'] = $done;
        $task->update(['checklist' => $list, 'status' => $task->status === 'open' ? 'in_progress' : $task->status]);

        return $task;
    }

    public function start(Task $task): Task
    {
        $this->assertOpen($task);
        $task->update(['status' => 'in_progress']);

        return $task;
    }

    public function addPhoto(Task $task, UploadedFile $file): TaskPhoto
    {
        $path = $file->storeAs("people/tasks/{$task->hotel_id}/{$task->id}", Str::uuid().'.'.($file->guessExtension() ?: 'jpg'), 'local');

        return TaskPhoto::create(['task_id' => $task->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $this->ctx->actor()?->id]);
    }

    public function complete(Task $task, ?string $note): Task
    {
        $this->assertOpen($task);
        if (collect($task->checklist)->contains(fn ($i) => ! $i['done'])) {
            throw new DomainException('Tick every checklist item first.', 'people-task-checklist-open');
        }
        if ($task->requires_photo && ! $task->photos()->exists()) {
            throw new DomainException('A photo proof is required to complete this task.', 'people-task-photo-required');
        }
        $task->update(['status' => 'done', 'completion_note' => $note, 'completed_at' => now(), 'completed_by' => $this->ctx->actor()?->id]);
        $this->bus->record('people.task_completed', ['id' => $task->id], 'people_task', $task->id);

        return $task;
    }

    public function cancel(Task $task): Task
    {
        $this->assertOpen($task);
        $task->update(['status' => 'cancelled']);

        return $task;
    }

    /** Can this employee act on the task (assignee or member of the assigned department)? */
    public function isAssignee(Task $task, ?Employee $e): bool
    {
        return $e && ($task->assignee_employee_id === $e->id || ($task->department_id && ! $task->assignee_employee_id && $task->department_id === $e->department_id));
    }

    private function assertOpen(Task $task): void
    {
        if (! in_array($task->status, ['open', 'in_progress'], true)) {
            throw new DomainException('This task is already '.$task->status.'.', 'people-task-closed');
        }
    }

    private function notifyAssignees(Task $task): void
    {
        $ids = $task->assignee_employee_id ? [$task->assignee_employee_id] : $this->people->idsInDepartment($task->department_id);
        $this->ensureTemplates();
        foreach (Employee::whereIn('id', $ids)->get() as $e) {
            if (! $e->email && ! $e->phone) {
                continue;
            }
            $this->notifier->notify(self::EVENT_ASSIGNED,
                ['email' => $e->email, 'phone' => $e->phone, 'name' => $e->full_name, 'locale' => $e->payslip_language ?? 'fr'],
                ['employee_name' => $e->full_name, 'task_title' => $task->title, 'priority' => $task->priority, 'due' => $task->due_at?->format('d/m/Y H:i') ?? '-'],
                ['type' => 'people_task', 'id' => $task->id]);
        }
    }

    private function ensureTemplates(): void
    {
        if (! $this->ctx->hotelId()) {
            return;
        }
        foreach (self::TEMPLATES as $locale => [$subject, $body]) {
            foreach (['email', 'sms'] as $channel) {
                NotificationTemplate::firstOrCreate(['event' => self::EVENT_ASSIGNED, 'channel' => $channel, 'locale' => $locale],
                    ['category' => 'transactional', 'subject' => $channel === 'email' ? $subject : null, 'body' => $body, 'active' => true]);
            }
        }
    }
}

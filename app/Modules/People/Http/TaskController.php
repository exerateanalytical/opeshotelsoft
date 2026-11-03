<?php

namespace App\Modules\People\Http;

use App\Modules\People\Application\TaskService;
use App\Modules\People\Domain\Task;
use App\Modules\People\Domain\TaskPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TaskController
{
    use Authorizes;

    public function __construct(private TaskService $tasks) {}

    public function index(Request $request)
    {
        $this->need($request, 'people.view', 'people.tasks', 'people.manage');
        $q = Task::withCount('photos')
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', (array) $v))
            ->when($request->query('priority'), fn ($q, $v) => $q->whereIn('priority', (array) $v))
            ->when($request->query('assignee_employee_id'), fn ($q, $v) => $q->where('assignee_employee_id', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v));

        return $this->present($this->order($q)->paginate(min(max((int) $request->query('per_page', 50), 1), 200)));
    }

    /** Tasks for me: assigned to me, or to my department without a named person. */
    public function mine(Request $request)
    {
        $me = $this->me($request);
        if (! $me) {
            return [];
        }
        $q = Task::withCount('photos')->whereIn('status', ['open', 'in_progress'])->where(fn ($w) => $w->where('assignee_employee_id', $me->id)
            ->orWhere(fn ($d) => $d->whereNull('assignee_employee_id')->where('department_id', $me->department_id ?? 0)));

        return $this->present($this->order($q)->get());
    }

    public function overdue(Request $request)
    {
        $this->need($request, 'people.view', 'people.tasks', 'people.manage');

        return $this->present($this->order(Task::withCount('photos')->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', now()))->get());
    }

    public function store(Request $request)
    {
        $this->need($request, 'people.tasks', 'people.manage');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string'],
            'assignee_employee_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'checklist' => ['sometimes', 'array', 'max:30'], 'checklist.*' => ['required', 'string', 'max:200'],
            'requires_photo' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->tasks->create($data), 201);
    }

    public function show(Request $request, Task $task)
    {
        $this->canAct($request, $task, true);

        return $this->present(collect([$task->load('photos')]))->first();
    }

    public function assign(Request $request, Task $task)
    {
        $this->need($request, 'people.tasks', 'people.manage');
        $data = $request->validate(['assignee_employee_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer']]);

        return $this->tasks->reassign($task, $data['assignee_employee_id'] ?? null, $data['department_id'] ?? null);
    }

    public function start(Request $request, Task $task)
    {
        $this->canAct($request, $task);

        return $this->tasks->start($task);
    }

    public function check(Request $request, Task $task, int $index)
    {
        $this->canAct($request, $task);

        return $this->tasks->check($task, $index, (bool) $request->validate(['done' => ['required', 'boolean']])['done']);
    }

    public function photo(Request $request, Task $task)
    {
        $this->canAct($request, $task);
        $request->validate(['photo' => ['required', 'file', 'image', 'max:8192']]);

        return response()->json($this->tasks->addPhoto($task, $request->file('photo')), 201);
    }

    public function downloadPhoto(Request $request, Task $task, TaskPhoto $photo)
    {
        $this->canAct($request, $task, true);
        abort_unless($photo->task_id === $task->id, 404);

        return Storage::disk('local')->response($photo->path);
    }

    public function complete(Request $request, Task $task)
    {
        $this->canAct($request, $task);

        return $this->tasks->complete($task, $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);
    }

    public function cancel(Request $request, Task $task)
    {
        $this->need($request, 'people.tasks', 'people.manage');

        return $this->tasks->cancel($task);
    }

    private function canAct(Request $request, Task $task, bool $read = false): void
    {
        if (! $this->tasks->isAssignee($task, $this->me($request))) {
            $read ? $this->need($request, 'people.view', 'people.tasks', 'people.manage') : $this->need($request, 'people.tasks', 'people.manage');
        }
    }

    private function order($q)
    {
        return $q->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderByRaw('due_at asc nulls last')->orderBy('id');
    }

    private function present($rows)
    {
        $this->withNames($rows, 'assignee_employee_id');
        collect($rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows->items() : $rows)->each(fn (Task $t) => $t->setAttribute('overdue', $t->isOverdue()));

        return $rows;
    }
}

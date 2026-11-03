<?php

namespace App\Platform\Http;

use App\Platform\Events\EventBus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Base controller for master-data resources (room types, items, accounts...).
 * Subclasses declare the model, permission, event prefix and validation;
 * tenant scoping comes from the model's BelongsToHotel/BelongsToGroup trait.
 *
 * Query params: filter[field]=value, q=search, sort=field|-field, per_page.
 */
abstract class ResourceController
{
    /** @var class-string<Model> */
    protected string $model;

    protected string $permission;

    protected string $event;

    protected array $searchable = ['name'];

    protected array $filterable = [];

    protected array $with = [];

    abstract protected function rules(Request $request, ?Model $existing): array;

    public function index(Request $request)
    {
        $this->authorize($request, 'view');
        $q = $this->model::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $field => $value) {
            if (in_array($field, $this->filterable, true)) {
                $q->where($field, $value);
            }
        }
        if (($term = $request->query('q')) && $this->searchable) {
            $q->where(function ($w) use ($term) {
                foreach ($this->searchable as $col) {
                    $w->orWhere($col, 'ilike', '%'.$term.'%');
                }
            });
        }
        $sort = (string) $request->query('sort', 'id');
        $q->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');

        return $q->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function show(Request $request, string $id)
    {
        $this->authorize($request, 'view');

        return $this->model::with($this->with)->findOrFail($id);
    }

    public function store(Request $request, EventBus $bus)
    {
        $this->authorize($request, 'manage');
        $data = $request->validate($this->rules($request, null));

        return DB::transaction(function () use ($data, $bus) {
            $record = $this->model::create($this->prepare($data, null));
            $this->afterSave($record, $data);
            $bus->record($this->event.'.created', $record->toArray(), $this->event, $record->getKey());

            return response()->json($record->fresh($this->with), 201);
        });
    }

    public function update(Request $request, EventBus $bus, string $id)
    {
        $this->authorize($request, 'manage');
        $record = $this->model::findOrFail($id);
        $data = $request->validate($this->rules($request, $record));

        return DB::transaction(function () use ($record, $data, $bus) {
            $record->update($this->prepare($data, $record));
            $this->afterSave($record, $data);
            $bus->record($this->event.'.updated', ['id' => $record->getKey(), 'changes' => $record->getChanges()], $this->event, $record->getKey());

            return $record->fresh($this->with);
        });
    }

    public function destroy(Request $request, EventBus $bus, string $id)
    {
        $this->authorize($request, 'manage');
        $record = $this->model::findOrFail($id);

        // Master data is deactivated, not deleted, so history stays intact.
        DB::transaction(function () use ($record, $bus) {
            $record->update(['active' => false]);
            $bus->record($this->event.'.deactivated', ['id' => $record->getKey()], $this->event, $record->getKey());
        });

        return response()->noContent();
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        return $data;
    }

    protected function afterSave(Model $record, array $data): void {}

    protected function authorize(Request $request, string $ability): void
    {
        $user = $request->user();
        $ok = $user->can($this->permission.'.manage') || ($ability === 'view' && $user->can($this->permission.'.view'));
        abort_unless($ok, 403, 'You do not have permission for this action.');
    }
}

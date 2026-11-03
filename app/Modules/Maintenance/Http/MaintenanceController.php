<?php

namespace App\Modules\Maintenance\Http;

use App\Modules\Maintenance\Application\MaintenanceService;
use App\Modules\Maintenance\Domain\PmSchedule;
use App\Modules\Maintenance\Domain\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceController
{
    public function __construct(private MaintenanceService $maintenance) {}

    public function index(Request $request)
    {
        $this->can($request, 'view');

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $search = trim((string) $request->query('q', ''));

        return WorkOrder::with(['room:id,number', 'assignee:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', (array) $v))
            ->when($request->query('priority'), fn ($q, $v) => $q->whereIn('priority', (array) $v))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('title', 'ilike', $like)->orWhere('description', 'ilike', $like));
            })
            ->when($request->query('assigned_to'), fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->query('room_id'), fn ($q, $v) => $q->where('room_id', $v))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('id')->paginate($perPage);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'view');

        return $workOrder->load(['room', 'asset:id,tag,name', 'assignee:id,name']);
    }

    /** Any staff member can report a fault. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'room_id' => ['nullable', 'integer'], 'asset_id' => ['nullable', 'integer'],
            'category' => ['sometimes', Rule::in(['electrical', 'plumbing', 'hvac', 'furniture', 'it', 'kitchen', 'general'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'room_out_of_order' => ['sometimes', 'boolean'], 'out_of_order_until' => ['nullable', 'date', 'after_or_equal:today'],
            'assigned_to' => ['nullable', 'integer'], 'due_at' => ['nullable', 'date'],
        ]);
        if (($data['room_out_of_order'] ?? false) && ! $request->user()->can('maintenance.manage') && ! $request->user()->can('housekeeping.manage')) {
            abort(403, 'Only maintenance or housekeeping managers can take a room out of order.');
        }

        return response()->json($this->maintenance->open($data), 201);
    }

    public function assign(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['user_id' => ['required', 'integer']]);

        return $this->maintenance->assign($workOrder, $data['user_id']);
    }

    public function start(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'manage');

        return $this->maintenance->start($workOrder);
    }

    public function complete(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'resolution' => ['required', 'string'], 'minutes' => ['sometimes', 'integer', 'min:0'],
            'parts' => ['sometimes', 'array'], 'parts.*.stock_item_id' => ['required', 'integer'], 'parts.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'labour_cost' => ['sometimes', 'integer', 'min:0'],
        ]);

        return $this->maintenance->complete($workOrder, $data['resolution'], $data['minutes'] ?? 0, $data['parts'] ?? [], $data['labour_cost'] ?? 0);
    }

    public function hold(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->maintenance->hold($workOrder, $data['reason']);
    }

    public function resume(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'manage');

        return $this->maintenance->resume($workOrder);
    }

    public function cancel(Request $request, WorkOrder $workOrder)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->maintenance->cancel($workOrder, $data['reason']);
    }

    public function updateSchedule(Request $request, PmSchedule $schedule)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'title' => ['sometimes', 'string'], 'asset_id' => ['sometimes', 'nullable', 'integer'], 'category' => ['sometimes', 'string'],
            'every_days' => ['sometimes', 'integer', 'between:1,730'], 'next_due' => ['sometimes', 'date'], 'active' => ['sometimes', 'boolean'],
        ]);
        $schedule->update($data);

        return $schedule;
    }

    public function schedules(Request $request)
    {
        $this->can($request, 'view');

        return PmSchedule::orderBy('next_due')->get();
    }

    public function storeSchedule(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'title' => ['required', 'string'], 'asset_id' => ['nullable', 'integer'], 'category' => ['sometimes', 'string'],
            'every_days' => ['required', 'integer', 'between:1,730'], 'next_due' => ['required', 'date'],
        ]);

        return response()->json(PmSchedule::create($data), 201);
    }

    public function runPreventive(Request $request)
    {
        $this->can($request, 'manage');

        return ['created' => $this->maintenance->runPreventive()];
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('maintenance.manage') || ($ability === 'view' && $u->can('maintenance.view')), 403);
    }
}

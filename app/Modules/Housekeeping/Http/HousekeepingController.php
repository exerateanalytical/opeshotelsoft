<?php

namespace App\Modules\Housekeeping\Http;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Domain\HkTask;
use App\Modules\Housekeeping\Domain\LostFoundItem;
use App\Modules\Property\Domain\Room;
use App\Modules\Tenancy\Domain\Hotel;
use App\Modules\Tenancy\Application\StaffDirectory;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

class HousekeepingController
{
    public function __construct(private HousekeepingService $hk, private TenantContext $ctx) {}

    /** Room status board. */
    public function board(Request $request)
    {
        $this->can($request, 'view');

        return Room::with('roomType:id,code')->where('active', true)->orderBy('floor')->orderBy('number')
            ->get(['id', 'number', 'floor', 'room_type_id', 'hk_status', 'occupied']);
    }

    /** Tasks for a date; attendants see only their own. */
    public function tasks(Request $request)
    {
        $this->can($request, 'view');
        $date = $request->query('date', Hotel::findOrFail($this->ctx->requireHotelId())->business_date->toDateString());
        $q = HkTask::with('room:id,number,floor,hk_status,occupied')->whereDate('date', $date);
        if (! $request->user()->can('housekeeping.manage') || $request->boolean('mine')) {
            $q->where('assigned_to', $request->user()->id);
        }

        return $q->orderBy('priority')->orderBy('id')->get();
    }

    public function generate(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['date' => ['nullable', 'date']]);

        return ['created' => $this->hk->generate($data['date'] ?? null)];
    }

    public function assign(Request $request, HkTask $task)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        if (! StaffDirectory::query($request->user()->group_id, $this->ctx->requireHotelId())->whereKey($data['user_id'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['user_id' => 'This user is not staff of the current hotel.']);
        }

        return $this->hk->assign($task, $data['user_id']);
    }

    public function start(Request $request, HkTask $task)
    {
        $this->own($request, $task);

        return $this->hk->start($task);
    }

    public function finish(Request $request, HkTask $task)
    {
        $this->own($request, $task);
        $data = $request->validate([
            'checklist' => ['sometimes', 'array'], 'notes' => ['nullable', 'string', 'max:1000'],
            'consumables' => ['sometimes', 'array'], 'consumables.*.stock_item_id' => ['required', 'integer'],
            'consumables.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        return $this->hk->finish($task, $data['checklist'] ?? [], $data['consumables'] ?? [], $data['notes'] ?? null);
    }

    public function inspect(Request $request, HkTask $task)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['passed' => ['required', 'boolean'], 'notes' => ['nullable', 'string']]);

        return $this->hk->inspect($task, $data['passed'], $data['notes'] ?? null);
    }

    public function setRoomStatus(Request $request, Room $room)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['status' => ['required', 'string'], 'reason' => ['nullable', 'string']]);

        return $this->hk->setRoomStatus($room, $data['status'], $data['reason'] ?? null);
    }

    public function lostFound(Request $request)
    {
        $this->can($request, 'view');

        $f = $request->validate([
            'status' => ['nullable', 'in:stored,returned,disposed'], 'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        return LostFoundItem::query()
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'ilike', "%$t%")->orWhere('location', 'ilike', "%$t%")))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('found_on', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('found_on', '<=', $d))
            ->orderByDesc('found_on')->orderByDesc('id')->paginate(50)->withQueryString();
    }

    public function storeLostFound(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'], 'location' => ['nullable', 'string'],
            'room_id' => ['nullable', 'integer'], 'guest_id' => ['nullable', 'integer'],
            'found_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $data['found_on'] ??= now()->toDateString();

        return response()->json(LostFoundItem::create($data + ['found_by' => $request->user()->id]), 201);
    }

    public function returnLostFound(Request $request, LostFoundItem $item)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['returned_to' => ['required', 'string']]);
        $item->update(['status' => 'returned', 'returned_to' => $data['returned_to'], 'returned_at' => now()]);

        return $item;
    }

    public function disposeLostFound(Request $request, LostFoundItem $item)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        if ($item->status !== 'stored') {
            throw new DomainException("Item is already {$item->status}.", 'lost-found-not-stored');
        }
        $item->update(['status' => 'disposed', 'disposal_reason' => $data['reason'], 'disposed_at' => now(), 'disposed_by' => $request->user()->id]);

        return $item;
    }

    private function own(Request $request, HkTask $task): void
    {
        abort_unless($request->user()->can('housekeeping.manage') || $task->assigned_to === $request->user()->id || ($task->assigned_to === null && $request->user()->can('housekeeping.view')), 403);
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('housekeeping.manage') || ($ability === 'view' && ($u->can('housekeeping.view') || $u->can('frontdesk.manage'))), 403);
    }
}

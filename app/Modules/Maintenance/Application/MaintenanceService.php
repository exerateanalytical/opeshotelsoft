<?php

namespace App\Modules\Maintenance\Application;

use App\Modules\Maintenance\Domain\PmSchedule;
use App\Modules\Maintenance\Domain\WorkOrder;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MaintenanceService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx, private Inventory $inventory) {}

    public function open(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $hotelId = $this->ctx->requireHotelId();
            $wo = WorkOrder::create([
                'number' => NumberSequence::next($hotelId, 'work-order', 'OT'),
                'room_id' => $data['room_id'] ?? null, 'asset_id' => $data['asset_id'] ?? null,
                'category' => $data['category'] ?? 'general', 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'priority' => $data['priority'] ?? 'normal',
                'room_out_of_order' => $data['room_out_of_order'] ?? false, 'reported_by' => $this->ctx->actor()?->id,
                'assigned_to' => $data['assigned_to'] ?? null, 'pm_schedule_id' => $data['pm_schedule_id'] ?? null,
                'due_at' => $data['due_at'] ?? null, 'status' => isset($data['assigned_to']) ? 'assigned' : 'open',
            ]);

            if ($wo->room_out_of_order && $wo->room_id) {
                $room = Room::lockForUpdate()->findOrFail($wo->room_id);
                if ($room->occupied) {
                    throw new DomainException("Room {$room->number} is occupied; move the guest before putting it out of order.");
                }
                $until = Carbon::parse($data['out_of_order_until'] ?? now()->addDay());
                $this->blockInventory($room, now(), $until, +1);
                $room->update(['hk_status' => 'out_of_order', 'notes' => "OOO until {$until->toDateString()} ({$wo->number})"]);
                $wo->forceFill(['due_at' => $wo->due_at ?? $until])->save();
                $this->bus->record('property.room_status_changed', ['room_id' => $room->id, 'room_number' => $room->number, 'to' => 'out_of_order', 'work_order' => $wo->number, 'until' => $until->toDateString()], 'room', $room->id);
            }
            $this->bus->record('maintenance.work_order_opened', $wo->only(['id', 'number', 'room_id', 'title', 'priority', 'room_out_of_order']), 'work_order', $wo->id);

            return $wo->refresh();
        });
    }

    /** Allowed source statuses per action. */
    private const TRANSITIONS = [
        'assign' => ['open', 'assigned', 'in_progress'],
        'start' => ['open', 'assigned'],
        'hold' => ['open', 'assigned', 'in_progress'],
        'resume' => ['on_hold'],
        'cancel' => ['open', 'assigned', 'in_progress', 'on_hold'],
        'complete' => ['open', 'assigned', 'in_progress'],
    ];

    private function guard(WorkOrder $wo, string $action): void
    {
        if (! in_array($wo->status, self::TRANSITIONS[$action], true)) {
            throw new DomainException("Cannot {$action} work order {$wo->number} while it is {$wo->status}.", 'invalid-state');
        }
    }

    public function assign(WorkOrder $wo, int $userId): WorkOrder
    {
        $this->guard($wo, 'assign');
        $wo->update(['assigned_to' => $userId, 'status' => 'assigned']);
        $this->bus->record('maintenance.work_order_assigned', ['id' => $wo->id, 'number' => $wo->number, 'assigned_to' => $userId], 'work_order', $wo->id);

        return $wo;
    }

    public function start(WorkOrder $wo): WorkOrder
    {
        $this->guard($wo, 'start');
        $wo->update(['status' => 'in_progress', 'assigned_to' => $wo->assigned_to ?? $this->ctx->actor()?->id]);

        return $wo;
    }

    public function hold(WorkOrder $wo, string $reason): WorkOrder
    {
        $this->guard($wo, 'hold');
        $wo->update(['held_from' => $wo->status, 'status' => 'on_hold', 'hold_reason' => $reason]);
        $this->bus->record('maintenance.work_order_held', ['id' => $wo->id, 'number' => $wo->number, 'reason' => $reason], 'work_order', $wo->id);

        return $wo;
    }

    public function resume(WorkOrder $wo): WorkOrder
    {
        $this->guard($wo, 'resume');
        $to = $wo->held_from ?? ($wo->assigned_to ? 'assigned' : 'open');
        $wo->update(['status' => $to, 'held_from' => null, 'hold_reason' => null]);
        $this->bus->record('maintenance.work_order_resumed', ['id' => $wo->id, 'number' => $wo->number, 'to' => $to], 'work_order', $wo->id);

        return $wo;
    }

    public function cancel(WorkOrder $wo, string $reason): WorkOrder
    {
        $this->guard($wo, 'cancel');

        return DB::transaction(function () use ($wo, $reason) {
            $wo->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'held_from' => null]);
            $this->releaseRoom($wo);
            $this->bus->record('maintenance.work_order_cancelled', ['id' => $wo->id, 'number' => $wo->number, 'reason' => $reason], 'work_order', $wo->id);

            return $wo->fresh();
        });
    }

    /** Lift the out-of-order status this work order put on its room. */
    private function releaseRoom(WorkOrder $wo): void
    {
        if ($wo->room_out_of_order && $wo->room_id) {
            $room = Room::lockForUpdate()->findOrFail($wo->room_id);
            $until = $wo->due_at ? Carbon::parse($wo->due_at) : now();
            if ($until->gte(now()->startOfDay())) {
                $this->blockInventory($room, now(), $until, -1);
            }
            $room->update(['hk_status' => 'vacant_dirty', 'notes' => null]);
        }
    }

    /** @param array<int, array{stock_item_id:int, quantity:float}> $parts */
    public function complete(WorkOrder $wo, string $resolution, int $minutes = 0, array $parts = [], int $labourCost = 0): WorkOrder
    {
        $this->guard($wo, 'complete');

        return DB::transaction(function () use ($wo, $resolution, $minutes, $parts, $labourCost) {
            $cost = 0;
            if ($parts) {
                $store = Store::where('kind', 'maintenance')->where('active', true)->first()
                    ?? throw new DomainException('Create a maintenance store to issue spare parts.');
                $stock = app(StockService::class);
                foreach ($parts as $p) {
                    $m = $stock->consume('issue', StockItem::findOrFail($p['stock_item_id']), $store, $p['quantity'], [
                        'reference' => $wo->number, 'source_type' => 'work_order', 'source_id' => $wo->id,
                    ]);
                    $cost += $m->value;
                }
            }
            $wo->update(['status' => 'done', 'resolution' => $resolution, 'minutes_spent' => $wo->minutes_spent + $minutes, 'parts_cost' => $wo->parts_cost + $cost, 'labour_cost' => $wo->labour_cost + $labourCost, 'completed_at' => now()]);
            $this->releaseRoom($wo);
            if ($wo->pm_schedule_id && ($pm = PmSchedule::find($wo->pm_schedule_id))) {
                $pm->update(['next_due' => now()->addDays($pm->every_days)->toDateString()]);
            }
            $this->bus->record('maintenance.work_order_completed', $wo->only(['id', 'number', 'room_id', 'minutes_spent', 'parts_cost', 'labour_cost']), 'work_order', $wo->id);

            return $wo->fresh();
        });
    }

    /** Open work orders for preventive schedules that are due. */
    public function runPreventive(): int
    {
        $n = 0;
        foreach (PmSchedule::where('active', true)->whereDate('next_due', '<=', now())->get() as $pm) {
            if (WorkOrder::where('pm_schedule_id', $pm->id)->whereNotIn('status', ['done', 'cancelled'])->exists()) {
                continue;
            }
            $this->open(['title' => $pm->title, 'category' => $pm->category, 'asset_id' => $pm->asset_id, 'pm_schedule_id' => $pm->id, 'priority' => 'normal']);
            $n++;
        }

        return $n;
    }

    private function blockInventory(Room $room, Carbon $from, Carbon $until, int $delta): void
    {
        for ($d = $from->copy()->startOfDay(); $d->lte($until); $d->addDay()) {
            $this->inventory->adjust($room->hotel_id, $room->room_type_id, $d->toDateString(), 'out_of_order', $delta);
        }
        $this->bus->record('availability.updated', ['room_type_ids' => [$room->room_type_id], 'from' => $from->toDateString(), 'to' => $until->toDateString()]);
    }
}

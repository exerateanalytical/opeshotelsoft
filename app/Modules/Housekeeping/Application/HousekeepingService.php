<?php

namespace App\Modules\Housekeeping\Application;

use App\Modules\Housekeeping\Domain\HkTask;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Daily task board, attendant workflow, inspection and room status. */
class HousekeepingService
{
    public const DEFAULT_CHECKLIST = ['Linge changé', 'Salle de bain nettoyée', 'Sols aspirés', 'Minibar vérifié', 'Produits d\'accueil réassortis', 'Climatisation testée'];

    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    /** Create today's tasks: departures, stayovers, and arrival preparation. */
    public function generate(?string $date = null): int
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $date ??= $hotel->business_date->toDateString();
        $created = 0;

        $departures = Reservation::where('status', 'checked_in')->whereDate('departure', $date)->pluck('room_id');
        $stayovers = Reservation::where('status', 'checked_in')->whereDate('departure', '>', $date)->pluck('room_id');
        $arrivals = Reservation::whereIn('status', ['confirmed'])->whereDate('arrival', $date)->whereNotNull('room_id')->pluck('room_id');
        $dirty = Room::where('hk_status', 'vacant_dirty')->pluck('id');

        $plan = [];
        foreach ($departures as $id) {
            $plan[$id] = ['departure', 1];
        }
        foreach ($stayovers as $id) {
            $plan[$id] ??= ['stayover', 3];
        }
        foreach ($arrivals as $id) {
            $plan[$id] ??= ['arrival_prep', 2];
        }
        foreach ($dirty as $id) {
            $plan[$id] ??= ['departure', 2];
        }

        foreach ($plan as $roomId => [$kind, $priority]) {
            $task = HkTask::firstOrCreate(['room_id' => $roomId, 'date' => $date, 'kind' => $kind], [
                'priority' => $priority, 'checklist' => array_map(fn ($c) => ['item' => $c, 'done' => false], self::DEFAULT_CHECKLIST),
            ]);
            $created += $task->wasRecentlyCreated ? 1 : 0;
        }
        $this->bus->record('housekeeping.tasks_generated', ['date' => $date, 'created' => $created]);

        return $created;
    }

    public function assign(HkTask $task, int $userId): HkTask
    {
        $task->update(['assigned_to' => $userId]);

        return $task;
    }

    public function start(HkTask $task): HkTask
    {
        if (! in_array($task->status, ['pending', 'failed'], true)) {
            throw new DomainException("Task is {$task->status}.");
        }
        $task->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_to' => $task->assigned_to ?? $this->ctx->actor()?->id]);

        return $task;
    }

    /** @param array<int, array{stock_item_id:int, quantity:float}> $consumables */
    public function finish(HkTask $task, array $checklist = [], array $consumables = [], ?string $notes = null): HkTask
    {
        if ($task->status !== 'in_progress') {
            throw new DomainException('Start the task before finishing it.');
        }

        return DB::transaction(function () use ($task, $checklist, $consumables, $notes) {
            $task->update([
                'status' => 'done', 'finished_at' => now(), 'checklist' => $checklist ?: $task->checklist,
                'consumables' => $consumables, 'notes' => $notes,
            ]);
            $room = Room::lockForUpdate()->findOrFail($task->room_id);
            if (! in_array($room->hk_status, ['out_of_order', 'out_of_service'], true)) {
                $room->update(['hk_status' => $room->occupied ? 'occupied_clean' : 'vacant_clean']);
            }

            if ($consumables && ($store = Store::where('kind', 'housekeeping')->where('active', true)->first())) {
                $stock = app(StockService::class);
                foreach ($consumables as $c) {
                    $stock->consume('issue', StockItem::findOrFail($c['stock_item_id']), $store, $c['quantity'], [
                        'reference' => "Chambre {$room->number}", 'source_type' => 'hk_task', 'source_id' => $task->id,
                    ]);
                }
            }
            $this->bus->record('housekeeping.room_cleaned', ['task_id' => $task->id, 'room_id' => $room->id, 'room_number' => $room->number, 'status' => $room->hk_status], 'room', $room->id);

            return $task->fresh('room');
        });
    }

    public function inspect(HkTask $task, bool $passed, ?string $notes = null): HkTask
    {
        if ($task->status !== 'done') {
            throw new DomainException('Only finished tasks can be inspected.');
        }

        return DB::transaction(function () use ($task, $passed, $notes) {
            $task->update(['status' => $passed ? 'inspected' : 'failed', 'inspected_by' => $this->ctx->actor()?->id, 'notes' => trim(($task->notes ?? '')."\n".($notes ?? ''))]);
            $room = Room::lockForUpdate()->findOrFail($task->room_id);
            if ($passed && ! $room->occupied) {
                $room->update(['hk_status' => 'inspected']);
            } elseif (! $passed) {
                $room->update(['hk_status' => $room->occupied ? 'occupied_dirty' : 'vacant_dirty']);
            }
            $this->bus->record('housekeeping.room_inspected', ['task_id' => $task->id, 'room_id' => $room->id, 'passed' => $passed], 'room', $room->id);

            return $task->fresh('room');
        });
    }

    /** Manual status change from the board (e.g. guest left early, mark dirty). */
    public function setRoomStatus(Room $room, string $status, ?string $reason = null): Room
    {
        if (! in_array($status, Room::STATUSES, true)) {
            throw new DomainException('Unknown room status.');
        }
        if ($room->occupied && str_starts_with($status, 'vacant')) {
            throw new DomainException("Room {$room->number} is occupied.");
        }
        $before = $room->hk_status;
        $room->update(['hk_status' => $status]);
        $this->bus->record('property.room_status_changed', ['room_id' => $room->id, 'room_number' => $room->number, 'from' => $before, 'to' => $status, 'reason' => $reason], 'room', $room->id);

        return $room;
    }
}

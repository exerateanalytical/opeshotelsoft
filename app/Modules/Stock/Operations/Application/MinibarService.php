<?php

namespace App\Modules\Stock\Operations\Application;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Operations\Domain\MinibarConsumption;
use App\Modules\Stock\Operations\Domain\MinibarPar;
use App\Modules\Stock\Operations\Domain\MinibarTask;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Minibar replenishment. Par per room type; consumption is posted to the guest folio (charge code MINI) and
 * deducted from the minibar store; check-out generates a restock task listing what the stay consumed;
 * completing the task transfers the stock from the main store to the minibar store.
 */
class MinibarService
{
    public const CHARGE_CODE = 'MINI';

    public function __construct(private StockService $stock, private FolioService $folios, private EventBus $bus, private TenantContext $ctx) {}

    public function setPar(int $roomTypeId, int $itemId, float $par, int $price): MinibarPar
    {
        StockItem::findOrFail($itemId);

        return MinibarPar::updateOrCreate(['room_type_id' => $roomTypeId, 'stock_item_id' => $itemId], ['par_qty' => $par, 'price' => $price]);
    }

    public function pars(?int $roomTypeId = null): array
    {
        $rows = MinibarPar::when($roomTypeId, fn ($q, $v) => $q->where('room_type_id', $v))->orderBy('room_type_id')->get();
        $items = StockItem::whereIn('id', $rows->pluck('stock_item_id'))->get()->keyBy('id');

        return $rows->map(fn ($p) => $p->toArray() + ['item_code' => $items[$p->stock_item_id]->code, 'item_name' => $items[$p->stock_item_id]->name, 'unit' => $items[$p->stock_item_id]->unit])->all();
    }

    /** Record what a guest took from the minibar; charge the folio and deduct the minibar store. */
    public function consume(int $roomId, array $items, ?int $minibarStoreId = null): array
    {
        $room = Room::findOrFail($roomId);
        $reservation = Reservation::where('room_id', $room->id)->where('status', 'checked_in')->first()
            ?? throw new DomainException("Room {$room->number} has no guest in house.", 'minibar-no-guest');
        $store = $this->minibarStore($minibarStoreId);
        $folio = $this->folios->guestFolioFor($reservation);

        return DB::transaction(function () use ($room, $reservation, $items, $store, $folio) {
            $out = [];
            foreach ($items as $it) {
                $qty = (float) $it['quantity'];
                $par = MinibarPar::where('room_type_id', $room->room_type_id)->where('stock_item_id', $it['stock_item_id'])->first()
                    ?? throw new DomainException('This item is not in the minibar of this room type.', 'minibar-not-listed');
                if ($qty <= 0) {
                    throw new DomainException('Quantity must be positive.', 'minibar-invalid');
                }
                $item = StockItem::findOrFail($par->stock_item_id);
                $c = MinibarConsumption::create([
                    'room_id' => $room->id, 'reservation_id' => $reservation->id, 'stock_item_id' => $item->id, 'quantity' => $qty,
                    'price' => $par->price, 'recorded_by' => $this->ctx->actor()?->id,
                ]);
                $line = $this->folios->charge($folio, self::CHARGE_CODE, (int) $par->price, [
                    'quantity' => $qty, 'description' => "Minibar {$item->name}", 'source_type' => 'minibar', 'source_id' => $c->id, 'reference' => "Minibar {$room->number}",
                ]);
                $m = $this->stock->consume('sale', $item, $store, $qty, ['reference' => "Minibar {$room->number}", 'source_type' => 'minibar', 'source_id' => $c->id]);
                $c->update(['folio_line_id' => $line->id, 'stock_movement_id' => $m->id]);
                $out[] = $c->fresh();
            }
            $this->bus->record('stock.minibar_consumed', ['room_id' => $room->id, 'reservation_id' => $reservation->id, 'lines' => count($out)], 'reservation', $reservation->id);

            return $out;
        });
    }

    /** Check-out: one restock task per room with what this stay consumed. */
    public function onCheckout(array $payload): ?MinibarTask
    {
        $room = Room::find($payload['room_id']);
        if (! $room || ! MinibarPar::where('room_type_id', $room->room_type_id)->exists()) {
            return null;
        }
        $consumed = MinibarConsumption::where('room_id', $room->id)->whereNull('minibar_task_id')
            ->when($payload['id'] ?? null, fn ($q, $v) => $q->where('reservation_id', $v))->get();
        $lines = $consumed->groupBy('stock_item_id')->map(fn ($g, $itemId) => ['stock_item_id' => (int) $itemId, 'quantity' => (float) $g->sum('quantity')])->values()->all();

        return DB::transaction(function () use ($room, $payload, $consumed, $lines) {
            $task = MinibarTask::create([
                'room_id' => $room->id, 'reservation_id' => $payload['id'] ?? null, 'lines' => $lines,
                'date' => Hotel::findOrFail($this->ctx->requireHotelId())->business_date,
            ]);
            MinibarConsumption::whereIn('id', $consumed->pluck('id'))->update(['minibar_task_id' => $task->id]);

            return $task;
        });
    }

    public function tasks(?string $status = null): array
    {
        $tasks = MinibarTask::when($status, fn ($q, $v) => $q->where('status', $v))->orderBy('id')->get();
        $rooms = Room::whereIn('id', $tasks->pluck('room_id'))->get()->keyBy('id');
        $items = StockItem::whereIn('id', $tasks->flatMap(fn ($t) => array_column($t->lines, 'stock_item_id'))->unique())->get()->keyBy('id');

        return $tasks->map(fn ($t) => ['id' => $t->id, 'room_id' => $t->room_id, 'room_number' => $rooms[$t->room_id]->number ?? null, 'status' => $t->status, 'date' => $t->date->toDateString(),
            'lines' => collect($t->lines)->map(fn ($l) => $l + ['item_name' => $items[$l['stock_item_id']]->name ?? null])->all()])->all();
    }

    /** Restock done: transfer the quantities (default: what was consumed) main store -> minibar store. */
    public function complete(MinibarTask $task, ?array $lines = null, ?int $fromStoreId = null, ?int $minibarStoreId = null): MinibarTask
    {
        if ($task->status !== 'pending') {
            throw new DomainException('This restock task is already done.', 'invalid-state');
        }
        $to = $this->minibarStore($minibarStoreId);
        $from = $fromStoreId ? Store::findOrFail($fromStoreId) : (Store::where('kind', 'main')->where('active', true)->first() ?? throw new DomainException('No main store to restock from.', 'minibar-no-source'));

        return DB::transaction(function () use ($task, $lines, $from, $to) {
            $room = Room::find($task->room_id);
            foreach ($lines ?? $task->lines as $l) {
                if ((float) $l['quantity'] > 0) {
                    $this->stock->transfer(StockItem::findOrFail($l['stock_item_id']), $from, $to, (float) $l['quantity'], ['reference' => "Minibar {$room?->number}", 'source_type' => 'minibar_task', 'source_id' => $task->id]);
                }
            }
            $task->update(['status' => 'done', 'done_by' => $this->ctx->actor()?->id, 'done_at' => now(), 'lines' => $lines ?? $task->lines]);

            return $task->fresh();
        });
    }

    private function minibarStore(?int $id): Store
    {
        return $id ? Store::findOrFail($id) : (Store::where('kind', 'minibar')->where('active', true)->first() ?? throw new DomainException('No minibar store is defined (store kind "minibar").', 'minibar-no-store'));
    }
}

<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Group/event room blocks: allot inventory, pick up via rooming list, release unused rooms. */
class BlockController
{
    public function __construct(private Inventory $inventory, private EventBus $bus, private TenantContext $ctx) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('reservations.view') || $request->user()->can('reservations.manage'), 403);

        $status = $request->query('status');

        return RoomBlock::with('allocations')
            ->when($status, fn ($q) => is_array($status) ? $q->whereIn('status', $status) : $q->whereIn('status', explode(',', $status)))
            ->orderByDesc('arrival')->paginate(50);
    }

    public function show(Request $request, RoomBlock $block)
    {
        abort_unless($request->user()->can('reservations.view') || $request->user()->can('reservations.manage'), 403);
        return $block->load('allocations');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('reservations.manage'), 403);
        $hotelId = $this->ctx->requireHotelId();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('room_blocks')->where('hotel_id', $hotelId)],
            'name' => ['required', 'string', 'max:150'],
            'company_id' => ['nullable', 'integer'],
            'rate_plan_id' => ['nullable', 'integer'],
            'arrival' => ['required', 'date'],
            'departure' => ['required', 'date', 'after:arrival'],
            'cutoff_date' => ['nullable', 'date', 'before_or_equal:arrival'],
            'status' => ['sometimes', Rule::in(['tentative', 'definite'])],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'allocations.*.rooms' => ['required', 'integer', 'between:1,500'],
            'allocations.*.rate' => ['nullable', 'integer', 'min:0'],
        ]);

        return DB::transaction(function () use ($data, $hotelId) {
            $block = RoomBlock::create(collect($data)->except('allocations')->all());
            $arrival = Carbon::parse($data['arrival']);
            $departure = Carbon::parse($data['departure']);
            foreach ($data['allocations'] as $a) {
                foreach ($this->inventory->nights($arrival, $departure) as $d) {
                    $grid = $this->inventory->grid($hotelId, $a['room_type_id'], $d, $d)[$d];
                    if ($grid['available'] < $a['rooms']) {
                        throw new DomainException("Only {$grid['available']} rooms of this type are free on $d.", 'not-available', 409);
                    }
                    $block->allocations()->create(['room_type_id' => $a['room_type_id'], 'date' => $d, 'rooms' => $a['rooms'], 'rate' => $a['rate'] ?? null]);
                    $this->inventory->adjust($hotelId, $a['room_type_id'], $d, 'blocked', $a['rooms']);
                }
            }
            $this->bus->record('reservation.block.created', ['id' => $block->id, 'code' => $block->code], 'room_block', $block->id);

            return response()->json($block->load('allocations'), 201);
        });
    }

    /** POST blocks/{id}/rooming-list — create one reservation per guest from the block. */
    public function roomingList(Request $request, RoomBlock $block, ReservationService $service)
    {
        abort_unless($request->user()->can('reservations.manage'), 403);
        $data = $request->validate([
            'rate_plan_id' => [$block->rate_plan_id ? 'nullable' : 'required', 'integer'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.guest_id' => ['required', 'integer'],
            'entries.*.room_type_id' => ['required', 'integer'],
            'entries.*.arrival' => ['nullable', 'date'],
            'entries.*.departure' => ['nullable', 'date'],
            'entries.*.adults' => ['nullable', 'integer'],
        ]);
        $planId = $data['rate_plan_id'] ?? $block->rate_plan_id;

        $created = DB::transaction(fn () => collect($data['entries'])->map(fn ($e) => $service->create([
            'guest_id' => $e['guest_id'], 'room_type_id' => $e['room_type_id'], 'rate_plan_id' => $planId,
            'arrival' => $e['arrival'] ?? $block->arrival->toDateString(), 'departure' => $e['departure'] ?? $block->departure->toDateString(),
            'adults' => $e['adults'] ?? 1, 'room_block_id' => $block->id, 'company_id' => $block->company_id,
            'booking_ref' => $block->code, 'channel' => 'corporate', 'enforce_restrictions' => false,
        ]))->all());

        return response()->json(['data' => $created], 201);
    }

    /** POST blocks/{id}/release — return un-picked-up rooms to general inventory. */
    public function release(Request $request, RoomBlock $block)
    {
        abort_unless($request->user()->can('reservations.manage'), 403);
        DB::transaction(function () use ($block) {
            foreach ($block->allocations as $a) {
                $left = $a->rooms - $a->picked_up;
                if ($left > 0) {
                    $this->inventory->adjust($block->hotel_id, $a->room_type_id, $a->date->toDateString(), 'blocked', -$left);
                    $a->update(['rooms' => $a->picked_up]);
                }
            }
            $block->update(['status' => 'released']);
            $this->bus->record('reservation.block.released', ['id' => $block->id], 'room_block', $block->id);
        });

        return $block->fresh('allocations');
    }
}

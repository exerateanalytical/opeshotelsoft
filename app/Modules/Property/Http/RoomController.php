<?php

namespace App\Modules\Property\Http;

use App\Modules\Property\Domain\Room;
use App\Platform\Events\EventBus;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomController extends ResourceController
{
    protected string $model = Room::class;

    protected string $permission = 'property';

    protected string $event = 'property.room';

    protected array $searchable = ['number'];

    protected array $filterable = ['room_type_id', 'hk_status', 'floor', 'occupied', 'active'];

    protected array $with = ['roomType:id,code,name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $req = $existing ? 'sometimes' : 'required';

        return [
            'room_type_id' => [$req, 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'number' => [$req, 'string', 'max:10', Rule::unique('rooms')->where('hotel_id', $hotelId)->ignore($existing?->id)],
            'floor' => ['nullable', 'string', 'max:10'],
            'wing' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** Bulk create rooms, e.g. numbers 101-120 of one type. */
    public function bulk(Request $request, EventBus $bus)
    {
        $this->authorize($request, 'manage');
        $hotelId = app(TenantContext::class)->requireHotelId();
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'gte:from', 'lte:'.($request->integer('from') + 500)],
            'floor' => ['nullable', 'string', 'max:10'],
        ]);

        $result = DB::transaction(function () use ($data, $bus) {
            $created = [];
            $skipped = [];
            $existing = Room::with('roomType:id,code')->whereIn('number', array_map('strval', range($data['from'], $data['to'])))
                ->get()->keyBy('number');
            for ($n = $data['from']; $n <= $data['to']; $n++) {
                $number = (string) $n;
                if ($room = $existing->get($number)) {
                    $skipped[] = ['number' => $number, 'reason' => (int) $room->room_type_id === (int) $data['room_type_id']
                        ? 'already exists'
                        : 'exists with type '.($room->roomType?->code ?? $room->room_type_id)];

                    continue;
                }
                Room::create(['number' => $number, 'room_type_id' => $data['room_type_id'], 'floor' => $data['floor'] ?? null]);
                $created[] = $number;
            }
            $bus->record('property.rooms.bulk_created', ['room_type_id' => $data['room_type_id'], 'count' => count($created)]);

            return ['created' => $created, 'skipped' => $skipped];
        });

        return response()->json($result, 201);
    }
}

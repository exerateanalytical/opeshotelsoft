<?php

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HotelController
{
    public function index()
    {
        return Hotel::orderBy('name')->paginate(50);
    }

    public function show(Hotel $hotel)
    {
        return $hotel;
    }

    public function store(Request $request, EventBus $bus)
    {
        abort_unless($request->user()->can('hotels.manage'), 403);
        $data = $this->validated($request);

        return DB::transaction(function () use ($data, $bus) {
            $hotel = Hotel::create($data);
            $bus->record('tenancy.hotel.created', $hotel->only(['id', 'code', 'name', 'country', 'currency']), 'hotel', $hotel->id);

            return response()->json($hotel, 201);
        });
    }

    public function update(Request $request, Hotel $hotel, EventBus $bus)
    {
        abort_unless($request->user()->can('hotels.manage'), 403);
        $data = $this->validated($request, $hotel);

        return DB::transaction(function () use ($hotel, $data, $bus) {
            $hotel->update($data);
            $bus->record('tenancy.hotel.updated', ['id' => $hotel->id, 'changes' => array_keys($hotel->getChanges())], 'hotel', $hotel->id);

            return $hotel;
        });
    }

    private function validated(Request $request, ?Hotel $hotel = null): array
    {
        $groupId = $request->user()->group_id;
        $req = $hotel ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:12', Rule::unique('hotels')->where('group_id', $groupId)->ignore($hotel?->id)],
            'name' => [$req, 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'trade_register' => ['nullable', 'string', 'max:50'],
            'country' => ['sometimes', 'string', 'size:2', Rule::in(['CM', 'GA', 'CG', 'TD', 'CF', 'GQ'])],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'stars' => ['nullable', 'integer', 'between:1,5'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', Rule::in(['fr', 'en'])],
            'check_in_time' => ['sometimes', 'date_format:H:i'],
            'check_out_time' => ['sometimes', 'date_format:H:i'],
            'settings' => ['sometimes', 'array'],
        ]);
    }
}

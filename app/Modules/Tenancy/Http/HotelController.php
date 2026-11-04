<?php

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HotelController
{
    /** Columns every staff member may read (hotel pickers). Full rows need hotels.view or hotels.manage. */
    private const PUBLIC_COLUMNS = ['id', 'code', 'name', 'city'];

    /** Printed on every invoice and receipt, so also readable by staff who print documents. */
    private const DOCUMENT_COLUMNS = ['legal_name', 'tax_id', 'trade_register', 'address', 'phone', 'email'];

    public function index(Request $request)
    {
        $q = Hotel::orderBy('name');

        return $this->full($request) ? $q->paginate(50) : $q->paginate(50, self::PUBLIC_COLUMNS);
    }

    public function show(Request $request, Hotel $hotel)
    {
        if ($this->full($request)) {
            return $hotel;
        }
        $cols = array_merge(self::PUBLIC_COLUMNS, self::DOCUMENT_COLUMNS);

        return collect($hotel->getAttributes())->only($cols)->all();
    }

    private function full(Request $request): bool
    {
        $u = $request->user();

        return $u->can('hotels.view') || $u->can('hotels.manage');
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

    /**
     * Activate or deactivate a hotel (GAPH). Refuses to deactivate the hotel the caller is working in
     * or the last active hotel of the group. Audit-logged with the reason.
     */
    public function status(Request $request, Hotel $hotel, EventBus $bus, TenantContext $ctx)
    {
        $actor = $request->user();
        abort_unless($actor->can('hotels.manage'), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if ($data['status'] === 'inactive') {
            abort_if((int) $ctx->hotelId() === (int) $hotel->id, 422, 'You cannot deactivate the hotel you are currently working in.');
        }

        return DB::transaction(function () use ($hotel, $data, $actor, $bus) {
            if ($data['status'] === 'inactive' && $hotel->status === 'active') {
                // Lock the group's active hotels so two concurrent requests cannot both pass the last-hotel guard.
                $active = Hotel::withoutGlobalScopes()->where('group_id', $hotel->group_id)->where('status', 'active')
                    ->lockForUpdate()->pluck('id');
                abort_if($active->count() <= 1, 422, 'The last active hotel of the group cannot be deactivated.');
            }
            $from = $hotel->status;
            $hotel->update(['status' => $data['status']]);
            activity('security')->causedBy($actor)->performedOn($hotel)->event('hotel_status_changed')
                ->withProperties(['from' => $from, 'to' => $data['status'], 'reason' => $data['reason'], 'ip' => request()->ip()])
                ->log('hotel_status_changed');
            $bus->record('tenancy.hotel.status_changed', ['id' => $hotel->id, 'from' => $from, 'to' => $data['status']], 'hotel', $hotel->id);

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

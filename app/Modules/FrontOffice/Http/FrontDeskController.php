<?php

namespace App\Modules\FrontOffice\Http;

use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\FrontOffice\Application\NightAudit;
use App\Modules\FrontOffice\Domain\GuestRegisterEntry;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FrontDeskController
{
    public function __construct(private FrontDeskService $desk, private TenantContext $ctx) {}

    /** Today's board: arrivals, departures, in-house, room status counts. */
    public function dashboard(Request $request)
    {
        $this->can($request);
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $d = $hotel->business_date->toDateString();
        $with = ['guest:id,first_name,last_name,vip', 'room:id,number,hk_status', 'roomType:id,code'];

        return [
            'business_date' => $d,
            'arrivals' => Reservation::with($with)->whereIn('status', ['confirmed', 'tentative'])->whereDate('arrival', $d)->get(),
            'departures' => Reservation::with($with)->where('status', 'checked_in')->whereDate('departure', '<=', $d)->get(),
            'in_house' => Reservation::with($with)->where('status', 'checked_in')->count(),
            'rooms' => Room::where('active', true)->selectRaw('hk_status, count(*) as n')->groupBy('hk_status')->pluck('n', 'hk_status'),
            'occupied' => Room::where('occupied', true)->count(),
            'total_rooms' => Room::where('active', true)->count(),
        ];
    }

    public function checkIn(Request $request, Reservation $reservation)
    {
        $this->can($request);
        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'allow_dirty' => ['sometimes', 'boolean'],
            'coming_from' => ['nullable', 'string', 'max:100'],
            'going_to' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'in:business,leisure,conference,transit,other'],
            'guest' => ['sometimes', 'array'],
            'guest.nationality' => ['nullable', 'string', 'size:2'],
            'guest.date_of_birth' => ['nullable', 'date'],
            'guest.id_type' => ['nullable', 'string'],
            'guest.id_number' => ['nullable', 'string', 'max:50'],
            'guest.id_expiry' => ['nullable', 'date'],
            'guest.id_issued_in' => ['nullable', 'string', 'size:2'],
            'guest.phone' => ['nullable', 'string'],
            'guest.email' => ['nullable', 'email'],
            'guest.profession' => ['nullable', 'string'],
        ]);

        return $this->desk->checkIn($reservation, $data);
    }

    public function checkOut(Request $request, Reservation $reservation)
    {
        $this->can($request);
        $data = $request->validate(['city_ledger_company_id' => ['nullable', 'integer'], 'waive_fee' => ['sometimes', 'boolean']]);
        abort_if(($data['waive_fee'] ?? false) && ! $request->user()->can('reservations.waive_fees'), 403, 'Waiving a check-out fee needs reservations.waive_fees.');

        return $this->desk->checkOut($reservation, $data);
    }

    public function moveRoom(Request $request, Reservation $reservation)
    {
        $this->can($request);
        $data = $request->validate(['room_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:255']]);

        return $this->desk->moveRoom($reservation, $data['room_id'], $data['reason']);
    }

    public function walkIn(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'guest_id' => ['required', 'integer'], 'room_type_id' => ['required', 'integer'], 'rate_plan_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'], 'nights' => ['required', 'integer', 'between:1,90'],
            'adults' => ['sometimes', 'integer'], 'children' => ['sometimes', 'integer'], 'allow_dirty' => ['sometimes', 'boolean'],
            'guest' => ['sometimes', 'array'],
        ]);

        return response()->json($this->desk->walkIn($data), 201);
    }

    public function auditCheck(Request $request, NightAudit $audit)
    {
        abort_unless($request->user()->can('frontdesk.night_audit'), 403);

        return $audit->preCheck(Hotel::findOrFail($this->ctx->requireHotelId()));
    }

    public function audit(Request $request, NightAudit $audit)
    {
        abort_unless($request->user()->can('frontdesk.night_audit'), 403);
        $data = $request->validate(['mark_no_shows' => ['sometimes', 'boolean']]);

        return $audit->run(Hotel::findOrFail($this->ctx->requireHotelId()), $data['mark_no_shows'] ?? true);
    }

    public function businessDays(Request $request)
    {
        $this->can($request);

        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200']]);

        return DB::table('business_days')->where('hotel_id', $this->ctx->requireHotelId())
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')->paginate((int) ($data['per_page'] ?? 60))->withQueryString();
    }

    /** Police register for a date range (export to authorities). */
    public function policeRegister(Request $request)
    {
        $this->can($request);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date'], 'format' => ['nullable', 'in:json,csv']]);

        $rows = GuestRegisterEntry::where('arrival', '<=', $data['to'])->where('departure', '>=', $data['from'])->orderBy('arrival')->get();

        if (($data['format'] ?? 'json') !== 'csv') {
            return $rows;
        }

        // Official PDF layout of the authorities is unknown; CSV (UTF-8 with BOM so Excel keeps the accents) for now.
        $columns = ['room_number', 'full_name', 'nationality', 'date_of_birth', 'place_of_birth', 'profession', 'id_type', 'id_number',
            'id_expiry', 'coming_from', 'going_to', 'purpose', 'arrival', 'departure', 'checked_out_at'];
        $filename = 'police-register-'.$data['from'].'-'.$data['to'].'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // UTF-8 BOM
            fputcsv($out, $columns, ';');
            foreach ($rows as $r) {
                fputcsv($out, array_map(function ($c) use ($r) {
                    $v = $r->{$c};

                    return $v instanceof \DateTimeInterface ? ($c === 'checked_out_at' ? $v->format('Y-m-d H:i') : $v->format('Y-m-d')) : $v;
                }, $columns), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('frontdesk.manage') || $request->user()->can('frontdesk.view'), 403);
    }
}

<?php

namespace App\Modules\Reservations\Experience\Http;

use App\Modules\Channels\Domain\Connection;
use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\CheckoutFeeRule;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Modules\Reservations\Experience\Application\ChannelHealth;
use App\Modules\Reservations\Experience\Application\DigitalCheckin;
use App\Modules\Reservations\Experience\Application\GroupBooking;
use App\Modules\Reservations\Experience\Application\RoomingImporter;
use App\Modules\Reservations\Experience\Application\RoomSuggester;
use App\Modules\Reservations\Experience\Domain\ExperienceService;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use App\Modules\Reservations\Experience\Domain\GuestReview;
use App\Modules\Reservations\Experience\Domain\RoomSuggestion;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Staff API of the reservation experience workstream (routes guarded by experience.view / experience.manage). */
class ExperienceController
{
    // ---- Group booking ----

    public function book(Request $request, GroupBooking $svc)
    {
        $d = $request->validate([
            'payer_guest_id' => ['nullable', 'integer'], 'payer' => ['nullable', 'array'], 'payer.first_name' => ['required_with:payer', 'string', 'max:100'],
            'payer.last_name' => ['required_with:payer', 'string', 'max:100'], 'payer.email' => ['nullable', 'email'], 'payer.phone' => ['nullable', 'string', 'max:30'],
            'arrival' => ['required', 'date'], 'departure' => ['required', 'date', 'after:arrival'], 'corporate_code' => ['nullable', 'string', 'max:24'],
            'channel' => ['nullable', 'string', 'max:20'], 'route_to_payer' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:1000'],
            'rooms' => ['required', 'array', 'min:1', 'max:30'], 'rooms.*.room_type_id' => ['required', 'integer'], 'rooms.*.rate_plan_id' => ['required', 'integer'],
            'rooms.*.adults' => ['nullable', 'integer', 'between:1,8'], 'rooms.*.children' => ['nullable', 'integer', 'between:0,6'],
            'rooms.*.guest_id' => ['nullable', 'integer'], 'rooms.*.guest' => ['nullable', 'array'], 'rooms.*.guest.first_name' => ['required_with:rooms.*.guest', 'string'],
            'rooms.*.guest.last_name' => ['required_with:rooms.*.guest', 'string'], 'rooms.*.guest.email' => ['nullable', 'email'], 'rooms.*.guest.phone' => ['nullable', 'string'],
            'rooms.*.special_requests' => ['nullable', 'string', 'max:1000'], 'rooms.*.promo_code' => ['nullable', 'string', 'max:24'],
            'rooms.*.arrival' => ['nullable', 'date'], 'rooms.*.departure' => ['nullable', 'date'], 'rooms.*.room_prefs' => ['nullable', 'array'],
        ]);
        abort_unless($d['payer_guest_id'] ?? $d['payer'] ?? null, 422, 'A payer is required.');

        return response()->json($svc->book($d), 201);
    }

    public function booking(string $ref, GroupBooking $svc)
    {
        return $svc->summary($ref);
    }

    // ---- Arrivals readiness, registration card, preferences ----

    public function arrivals(Request $request, DigitalCheckin $checkin)
    {
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? Hotel::findOrFail(app(TenantContext::class)->requireHotelId())->business_date->toDateString();
        $rows = Reservation::with('guest:id,first_name,last_name,vip', 'roomType:id,code,name', 'room:id,number,hk_status,occupied')
            ->whereIn('status', ['tentative', 'confirmed'])->whereDate('arrival', $date)->orderBy('eta')->orderBy('id')->get();
        $suggestions = RoomSuggestion::with('room:id,number,floor,hk_status')->whereIn('reservation_id', $rows->pluck('id'))->where('status', 'pending')->orderByDesc('score')->get()->groupBy('reservation_id');

        return [
            'date' => $date,
            'arrivals' => $rows->map(fn (Reservation $r) => [
                'id' => $r->id, 'number' => $r->number, 'booking_ref' => $r->booking_ref, 'status' => $r->status, 'guest' => trim($r->guest->first_name.' '.$r->guest->last_name), 'vip' => (bool) $r->guest->vip,
                'room_type' => $r->roomType?->name, 'room' => $r->room?->number, 'room_status' => $r->room?->hk_status, 'eta' => $r->eta ? substr((string) $r->eta, 0, 5) : null,
                'nights' => $r->nightCount(), 'adults' => $r->adults, 'children' => $r->children, 'prefs' => $r->room_prefs, 'readiness' => $checkin->readiness($r),
                'suggestions' => ($suggestions[$r->id] ?? collect())->map(fn ($s) => ['id' => $s->id, 'room_id' => $s->room_id, 'room' => $s->room?->number, 'floor' => $s->room?->floor, 'score' => $s->score, 'reasons' => $s->reasons])->values(),
            ])->values(),
            'summary' => $rows->map(fn ($r) => $checkin->readiness($r)['status'])->countBy()->all(),
        ];
    }

    public function card(Reservation $reservation, DigitalCheckin $checkin)
    {
        return $checkin->card($reservation);
    }

    public function captureCheckin(Request $request, Reservation $reservation, DigitalCheckin $checkin)
    {
        $d = $request->validate([
            'id_type' => ['nullable', 'in:passport,national_id,residence_permit,driving_licence'], 'id_number' => ['nullable', 'string', 'max:60'],
            'nationality' => ['nullable', 'string', 'size:2'], 'id_country' => ['nullable', 'string', 'size:2'], 'id_expiry' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'], 'address' => ['nullable', 'string', 'max:255'], 'eta' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'], 'id_photo' => ['nullable', 'string'], 'signature' => ['nullable', 'string'], 'card_accepted' => ['sometimes', 'boolean'],
        ]);
        $checkin->submit($reservation->loadMissing('guest'), $d, 'staff');

        return $checkin->card($reservation->refresh());
    }

    public function preferences(Request $request, Reservation $reservation)
    {
        $d = $request->validate(['floor' => ['nullable', 'string', 'max:10'], 'view' => ['nullable', 'string', 'max:30'], 'quiet' => ['sometimes', 'boolean'], 'together' => ['nullable', 'string', 'max:20']]);
        $reservation->update(['room_prefs' => array_filter($d, fn ($v) => $v !== null && $v !== '' && $v !== false) ?: null]);

        return ['room_prefs' => $reservation->room_prefs];
    }

    // ---- Smart room assignment ----

    public function suggest(Request $request, RoomSuggester $svc)
    {
        $d = $request->validate(['date' => ['required', 'date']]);

        return ['data' => $svc->suggestForDate($d['date'])->map(fn ($s) => $s->load('room:id,number,floor')->only(['id', 'reservation_id', 'room_id', 'score', 'reasons', 'status']) + ['room' => $s->room?->number])->values()];
    }

    public function accept(Request $request, RoomSuggestion $suggestion, RoomSuggester $svc)
    {
        $r = $svc->accept($suggestion, $request->user());

        return ['status' => 'accepted', 'reservation_id' => $r->id, 'room_id' => $r->room_id];
    }

    public function reject(Request $request, RoomSuggestion $suggestion, RoomSuggester $svc)
    {
        return ['status' => $svc->reject($suggestion, $request->user())->status];
    }

    // ---- Rooming list ----

    public function roomingTemplate()
    {
        return response(RoomingImporter::template(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="rooming-list-template.csv"']);
    }

    public function roomingImport(Request $request, RoomBlock $block, RoomingImporter $svc)
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:5120'], 'commit' => ['sometimes', 'boolean'], 'rate_plan_id' => ['nullable', 'integer'], 'skip_invalid' => ['sometimes', 'boolean']]);
        $file = $request->file('file');
        $res = $svc->import($block, $file->getRealPath(), $file->getClientOriginalName(), (bool) ($d['commit'] ?? false), $d['rate_plan_id'] ?? null, (bool) ($d['skip_invalid'] ?? false));

        return response()->json($res, $res['status'] === 'imported' ? 201 : 200);
    }

    // ---- Channel health ----

    public function channelHealth(ChannelHealth $svc)
    {
        return ['data' => $svc->board()];
    }

    public function channelRetry(Request $request, Connection $connection, ChannelHealth $svc)
    {
        abort_unless($request->user()->can('channels.manage'), 403);
        $svc->retry($connection);

        return response()->json(['status' => 'queued'], 202);
    }

    // ---- Settings, services, reviews ----

    public function settings()
    {
        return ExperienceSetting::forCurrentHotel();
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate([
            'deposit_deadline_hours' => ['sometimes', 'integer', 'between:1,720'], 'deposit_reminder_hours' => ['sometimes', 'integer', 'between:1,720'],
            'early_checkin_enabled' => ['sometimes', 'boolean'], 'late_checkout_enabled' => ['sometimes', 'boolean'], 'no_show_fee' => ['sometimes', 'boolean'],
            'auto_release' => ['sometimes', 'boolean'], 'auto_no_show' => ['sometimes', 'boolean'], 'guest_modify' => ['sometimes', 'boolean'], 'guest_modify_cutoff_hours' => ['sometimes', 'integer', 'between:0,720'],
        ]);
        $s = ExperienceSetting::forCurrentHotel();
        $s->update($d);

        return $s->refresh();
    }

    public function services()
    {
        return ['data' => ExperienceService::orderBy('name')->get()];
    }

    public function saveService(Request $request, ?ExperienceService $service = null)
    {
        $d = $request->validate(['code' => [$service ? 'sometimes' : 'required', 'string', 'max:24'], 'name' => [$service ? 'sometimes' : 'required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'], 'price' => [$service ? 'sometimes' : 'required', 'integer', 'min:0'], 'charge_code' => [$service ? 'sometimes' : 'required', 'string', 'max:16'], 'active' => ['sometimes', 'boolean']]);
        $service ? $service->update($d) : $service = ExperienceService::create($d);

        return response()->json($service->refresh(), $request->isMethod('post') ? 201 : 200);
    }

    public function reviews(Request $request)
    {
        $page = GuestReview::when($request->integer('rating'), fn ($q, $n) => $q->where('rating', $n))->orderByDesc('submitted_at')->paginate(50);
        $stays = Reservation::with('guest:id,first_name,last_name')->whereIn('id', $page->pluck('reservation_id'))->get()->keyBy('id');
        $page->getCollection()->transform(function ($r) use ($stays) {
            $s = $stays->get($r->reservation_id);

            return $r->toArray() + ['stay' => $s ? ['number' => $s->number, 'arrival' => $s->arrival?->toDateString(), 'departure' => $s->departure?->toDateString(), 'guest' => trim(($s->guest?->first_name ?? '').' '.($s->guest?->last_name ?? ''))] : null];
        });

        return $page;
    }

    // ---- Early check-in / late check-out fee rules (additive: the front desk PUT replaces the whole list) ----

    public function feeRules()
    {
        return ['data' => CheckoutFeeRule::whereIn('kind', ['early_checkin', 'late'])->orderBy('kind')->orderBy('after_hour')->get()];
    }

    public function saveFeeRule(Request $request, ?CheckoutFeeRule $rule = null)
    {
        $new = ! $rule;
        $d = $request->validate([
            'kind' => [$new ? 'required' : 'sometimes', 'in:early_checkin,late'], 'after_hour' => [$new ? 'required' : 'sometimes', 'integer', 'between:0,23'],
            'charge_code' => [$new ? 'required' : 'sometimes', 'string', 'max:16'], 'fee_type' => [$new ? 'required' : 'sometimes', 'in:percent_of_night,fixed'],
            'value' => [$new ? 'required' : 'sometimes', 'integer', 'min:1'], 'label' => ['nullable', 'string', 'max:60'], 'active' => ['sometimes', 'boolean'],
        ]);
        if ($rule && ! in_array($rule->kind, ['early_checkin', 'late'], true)) {
            throw new DomainException('This rule is managed from the front desk settings.');
        }
        $code = $d['charge_code'] ?? $rule?->charge_code;
        if (! ChargeCode::where('code', $code)->exists()) {
            throw new DomainException("Unknown charge code $code.");
        }
        if (($d['fee_type'] ?? $rule?->fee_type) === 'percent_of_night' && ($d['value'] ?? $rule?->value) > 100) {
            throw new DomainException('A percentage fee cannot exceed 100%.');
        }
        $rule ? $rule->update($d) : $rule = CheckoutFeeRule::create($d + ['active' => true]);

        return response()->json($rule->refresh(), $new ? 201 : 200);
    }
}

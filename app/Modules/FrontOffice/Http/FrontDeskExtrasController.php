<?php

namespace App\Modules\FrontOffice\Http;

use App\Modules\FrontOffice\Application\CheckoutFees;
use App\Modules\FrontOffice\Domain\CheckoutFeeRule;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\FolioRoutingRule;
use App\Modules\FrontOffice\Domain\GuestRegisterEntry;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;
use App\Modules\FrontOffice\Domain\ChargeCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** In-house list, check-out fees, room-move picker, folio routing and registration card. */
class FrontDeskExtrasController
{
    public function __construct(private CheckoutFees $fees, private EventBus $bus) {}

    /** Guests in house with room, balance, departure, VIP and company. */
    public function inHouse(Request $request)
    {
        $this->view($request);
        $q = trim((string) $request->query('q', ''));

        $rows = Reservation::with(['guest:id,first_name,last_name,vip,phone', 'room:id,number', 'roomType:id,code,name', 'company:id,name'])
            ->where('status', 'checked_in')
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('number', 'ilike', "%$q%")
                ->orWhereHas('guest', fn ($g) => $g->where(DB::raw("first_name || ' ' || last_name"), 'ilike', "%$q%"))
                ->orWhereHas('room', fn ($g) => $g->where('number', 'ilike', "%$q%"))
                ->orWhereHas('company', fn ($g) => $g->where('name', 'ilike', "%$q%"))))
            ->orderBy('departure')->get();

        $balances = Folio::whereIn('reservation_id', $rows->pluck('id'))->where('status', 'open')
            ->selectRaw('reservation_id, sum(balance) as total')->groupBy('reservation_id')->pluck('total', 'reservation_id');

        return $rows->map(fn (Reservation $r) => [
            'id' => $r->id, 'number' => $r->number,
            'guest' => $r->guest ? ['name' => trim($r->guest->first_name.' '.$r->guest->last_name), 'vip' => (bool) $r->guest->vip, 'phone' => $r->guest->phone] : null,
            'room' => $r->room?->number, 'room_type' => $r->roomType?->code,
            'company' => $r->company?->name,
            'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(),
            'adults' => $r->adults, 'balance' => (int) ($balances[$r->id] ?? 0),
        ])->values();
    }

    /** Vacant, sellable rooms a checked-in guest can move to. */
    public function vacantRooms(Request $request, Reservation $reservation)
    {
        $this->view($request);

        return Room::with('roomType:id,code,name')->where('active', true)->where('occupied', false)
            ->whereNotIn('hk_status', ['out_of_order', 'out_of_service'])->where('id', '!=', $reservation->room_id)
            ->orderBy('number')->get(['id', 'number', 'room_type_id', 'hk_status']);
    }

    public function checkoutQuote(Request $request, Reservation $reservation)
    {
        $this->view($request);

        return $this->fees->quote($reservation);
    }

    public function postCheckoutFee(Request $request, Reservation $reservation)
    {
        $this->manage($request);

        return response()->json($this->fees->post($reservation), 201);
    }

    public function feeRules(Request $request)
    {
        $this->view($request);

        return CheckoutFeeRule::orderBy('kind')->orderBy('after_hour')->get();
    }

    /** Replace the hotel's fee rules with the given list. */
    public function saveFeeRules(Request $request)
    {
        abort_unless($request->user()->can('frontdesk.configure'), 403);
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:20'],
            'rules.*.kind' => ['required', 'in:late,early'],
            'rules.*.after_hour' => ['required_if:rules.*.kind,late', 'nullable', 'integer', 'between:0,23'],
            'rules.*.charge_code' => ['required', 'string', 'max:16'],
            'rules.*.fee_type' => ['required', 'in:percent_of_night,fixed'],
            'rules.*.value' => ['required', 'integer', 'min:1'],
            'rules.*.label' => ['nullable', 'string', 'max:60'],
            'rules.*.active' => ['sometimes', 'boolean'],
        ]);
        foreach ($data['rules'] as $r) {
            if (! ChargeCode::where('code', $r['charge_code'])->exists()) {
                throw new DomainException("Unknown charge code {$r['charge_code']}.");
            }
            if ($r['fee_type'] === 'percent_of_night' && $r['value'] > 100) {
                throw new DomainException('A percentage fee cannot exceed 100%.');
            }
        }

        return DB::transaction(function () use ($data) {
            CheckoutFeeRule::query()->delete();
            foreach ($data['rules'] as $r) {
                CheckoutFeeRule::create(['after_hour' => $r['after_hour'] ?? 0, 'active' => $r['active'] ?? true] + $r);
            }
            $this->bus->record('frontoffice.checkout_fee_rules_changed', ['rules' => $data['rules']], 'hotel', null);

            return CheckoutFeeRule::orderBy('kind')->orderBy('after_hour')->get();
        });
    }

    public function routingRules(Request $request, Reservation $reservation)
    {
        $this->view($request);

        return FolioRoutingRule::where('reservation_id', $reservation->id)->orderBy('id')->get()
            ->map(fn ($r) => $r->toArray() + ['target' => Folio::find($r->target_folio_id)?->only(['id', 'number', 'name', 'kind', 'status'])]);
    }

    public function addRoutingRule(Request $request, Reservation $reservation)
    {
        abort_unless($request->user()->can('folios.manage'), 403);
        $data = $request->validate([
            'match_type' => ['required', 'in:charge_code,department,all'],
            'match_value' => ['required_unless:match_type,all', 'nullable', 'string', 'max:32'],
            'target_folio_id' => ['required', 'integer'],
        ]);
        $target = Folio::findOrFail($data['target_folio_id']);
        if ($target->status !== 'open') {
            throw new DomainException("Folio {$target->number} is closed.", 'folio-closed');
        }
        if ($target->reservation_id === $reservation->id && $target->kind === 'guest') {
            throw new DomainException('Route to a different folio than the guest folio.');
        }
        $rule = FolioRoutingRule::create([
            'reservation_id' => $reservation->id, 'match_type' => $data['match_type'],
            'match_value' => $data['match_type'] === 'all' ? null : $data['match_value'],
            'target_folio_id' => $target->id, 'created_by' => $request->user()->id,
        ]);
        $this->bus->record('folio.routing_rule_added', $rule->toArray(), 'reservation', $reservation->id);

        return response()->json($rule, 201);
    }

    public function deleteRoutingRule(Request $request, Reservation $reservation, FolioRoutingRule $rule)
    {
        abort_unless($request->user()->can('folios.manage'), 403);
        abort_unless($rule->reservation_id === $reservation->id, 404);
        $rule->delete();
        $this->bus->record('folio.routing_rule_removed', ['id' => $rule->id], 'reservation', $reservation->id);

        return response()->noContent();
    }

    /** Registration card data (W05 renders the PDF from this). */
    public function registrationCard(Request $request, Reservation $reservation)
    {
        $this->view($request);
        $e = $this->entry($reservation);

        return $e->toArray() + [
            'has_id_photo' => (bool) $e->id_photo_path,
            'has_signature' => (bool) $e->signature_path,
            'id_photo_url' => $e->id_photo_path ? "/api/v1/reservations/{$reservation->id}/registration-card/id-photo" : null,
            'signature_url' => $e->signature_path ? "/api/v1/reservations/{$reservation->id}/registration-card/signature" : null,
        ];
    }

    /** Store an ID photo (file or data URL) and/or a signature (PNG data URL). */
    public function saveRegistrationCard(Request $request, Reservation $reservation)
    {
        $this->manage($request);
        $request->validate([
            'id_photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
            'id_photo_data' => ['nullable', 'string', 'max:7000000'],
            'signature' => ['nullable', 'string', 'max:2000000'],
        ]);
        $e = $this->entry($reservation);
        $dir = "registration-cards/{$e->hotel_id}/{$reservation->id}";
        $changes = [];

        if ($file = $request->file('id_photo')) {
            $changes['id_photo_path'] = $this->store($dir, 'id-photo', file_get_contents($file->getRealPath()), ['image/jpeg', 'image/png', 'image/webp']);
        } elseif ($request->filled('id_photo_data')) {
            $changes['id_photo_path'] = $this->store($dir, 'id-photo', $this->decode($request->input('id_photo_data')), ['image/jpeg', 'image/png', 'image/webp']);
        }
        if ($request->filled('signature')) {
            $changes['signature_path'] = $this->store($dir, 'signature', $this->decode($request->input('signature')), ['image/png']);
        }
        if (! $changes) {
            throw new DomainException('Send an ID photo or a signature.');
        }
        foreach (['id_photo_path', 'signature_path'] as $k) {
            if (isset($changes[$k]) && $e->{$k} && $e->{$k} !== $changes[$k]) {
                Storage::disk('local')->delete($e->{$k});
            }
        }
        $e->update($changes + ['card_captured_at' => now(), 'card_captured_by' => $request->user()->id]);
        $this->bus->record('frontoffice.registration_card_captured', ['reservation_id' => $reservation->id, 'fields' => array_keys($changes)], 'reservation', $reservation->id);

        return $this->registrationCard($request, $reservation);
    }

    public function idPhoto(Request $request, Reservation $reservation)
    {
        return $this->serve($request, $reservation, 'id_photo_path');
    }

    public function signature(Request $request, Reservation $reservation)
    {
        return $this->serve($request, $reservation, 'signature_path');
    }

    private function serve(Request $request, Reservation $reservation, string $column)
    {
        $this->view($request);
        $path = $this->entry($reservation)->{$column};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    private function entry(Reservation $reservation): GuestRegisterEntry
    {
        return GuestRegisterEntry::where('reservation_id', $reservation->id)->latest('id')->first()
            ?? throw new DomainException('The guest is not checked in yet; there is no registration card.', 'no-register-entry', 404);
    }

    private function decode(string $data): string
    {
        if (! preg_match('#^data:image/[a-z+]+;base64,(.+)$#s', $data, $m) || ($bin = base64_decode($m[1], true)) === false) {
            throw new DomainException('Images must be sent as base64 data URLs.');
        }
        if (strlen($bin) > 5 * 1024 * 1024) {
            throw new DomainException('The image is larger than 5 MB.');
        }

        return $bin;
    }

    /** @param string[] $allowed */
    private function store(string $dir, string $name, string $bin, array $allowed): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin);
        if (! in_array($mime, $allowed, true)) {
            throw new DomainException('Unsupported image type '.$mime.'.');
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $path = "$dir/$name-".bin2hex(random_bytes(6)).".$ext";
        Storage::disk('local')->put($path, $bin);

        return $path;
    }

    private function view(Request $request): void
    {
        abort_unless($request->user()->can('frontdesk.view') || $request->user()->can('frontdesk.manage'), 403);
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()->can('frontdesk.manage'), 403);
    }
}

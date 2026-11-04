<?php

namespace App\Modules\FrontOffice\Http;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Guests\Domain\Company;
use App\Modules\Reservations\Domain\Reservation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FolioController
{
    public function __construct(private FolioService $folios) {}

    public function index(Request $request)
    {
        $this->can($request, 'view');

        return Folio::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('reservation_id'), fn ($q, $v) => $q->where('reservation_id', $v))
            ->when($request->query('company_id'), fn ($q, $v) => $q->where('company_id', $v))
            // The folio's own guest, or the guest of the reservation it belongs to.
            ->when($request->query('guest_id'), fn ($q, $v) => $q->where(fn ($w) => $w->where('guest_id', $v)
                ->orWhereIn('reservation_id', fn ($r) => $r->select('id')->from('reservations')->where('guest_id', $v))))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'ilike', '%'.$v.'%')->orWhere('name', 'ilike', '%'.$v.'%')))
            ->orderByDesc('id')->paginate(50);
    }

    public function show(Request $request, Folio $folio)
    {
        $this->can($request, 'view');

        return $folio->load('lines');
    }

    public function forReservation(Request $request, Reservation $reservation)
    {
        $this->can($request, 'manage');

        // A finished stay shows its settled folio; opening a new one here would leave a stray open folio behind.
        if (in_array($reservation->status, ['checked_out', 'cancelled', 'no_show'], true)) {
            return Folio::where('reservation_id', $reservation->id)->where('kind', 'guest')
                ->orderByRaw("status = 'open'")->orderByDesc('id')->firstOrFail()->load('lines');
        }

        return $this->folios->guestFolioFor($reservation)->load('lines');
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['master', 'company', 'house', 'walk_in'])],
            'name' => ['required', 'string', 'max:150'],
            'reservation_id' => ['nullable', 'integer'],
            'guest_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
        ]);
        $res = isset($data['reservation_id']) ? Reservation::findOrFail($data['reservation_id']) : null;

        return response()->json($this->folios->open($data['kind'], $data['name'], $res, $data['guest_id'] ?? null, $data['company_id'] ?? null), 201);
    }

    public function charge(Request $request, Folio $folio)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'charge_code' => ['required', 'string'], 'amount' => ['required', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'numeric', 'min:0.001'], 'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->folios->charge($folio, $data['charge_code'], $data['amount'], array_filter([
            'quantity' => $data['quantity'] ?? null, 'description' => $data['description'] ?? null, 'reference' => $data['reference'] ?? null,
        ])), 201);
    }

    public function payment(Request $request, Folio $folio)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'method' => ['required', 'string'], 'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->folios->payment($folio, $data['method'], $data['amount'], $data['reference'] ?? null), 201);
    }

    public function refund(Request $request, Folio $folio)
    {
        $user = $request->user();
        abort_unless($user->can('folios.adjust') || $user->can('payments.refund'), 403);
        $data = $request->validate(['method' => ['required', 'string'], 'amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string']]);

        return response()->json($this->folios->refund($folio, $data['method'], $data['amount'], $data['reason']), 201);
    }

    public function reverse(Request $request, Folio $folio, int $line)
    {
        abort_unless($request->user()->can('folios.void'), 403, 'Voids need manager permission.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $line = FolioLine::where('folio_id', $folio->id)->findOrFail($line);

        return response()->json($this->folios->reverse($line, $data['reason']), 201);
    }

    public function transfer(Request $request, Folio $folio)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['to_folio_id' => ['required', 'integer'], 'amount' => ['required', 'integer', 'min:1'], 'description' => ['nullable', 'string']]);
        $to = Folio::findOrFail($data['to_folio_id']);
        // Credit billing goes through the city-ledger route (company approval and credit limit), never a plain transfer (SEC1 audit).
        abort_if($to->kind === 'city_ledger', 422, 'Use the city-ledger transfer for company billing.');

        return $this->folios->transfer($folio, $to, $data['amount'], $data['description'] ?? 'Transfer');
    }

    public function cityLedger(Request $request, Folio $folio)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['company_id' => ['required', 'integer']]);

        return $this->folios->transferToCityLedger($folio, Company::findOrFail($data['company_id']));
    }

    public function close(Request $request, Folio $folio)
    {
        $this->can($request, 'manage');

        return $this->folios->close($folio);
    }

    public function invoice(Request $request, Folio $folio)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['name' => ['nullable', 'string'], 'tax_id' => ['nullable', 'string'], 'address' => ['nullable', 'string']]);

        return response()->json($this->folios->invoice($folio, array_filter($data)), 201);
    }

    public function invoices(Request $request)
    {
        $this->can($request, 'view');

        return Invoice::query()
            ->when($request->query('company_id'), fn ($q, $v) => $q->where('company_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'ilike', '%'.$v.'%')->orWhere('bill_to_name', 'ilike', '%'.$v.'%')))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function showInvoice(Request $request, Invoice $invoice)
    {
        $this->can($request, 'view');

        return $invoice;
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('folios.manage') || ($ability === 'view' && ($u->can('folios.view') || $u->can('accounting.view'))), 403);
    }
}

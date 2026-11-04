<?php

namespace App\Modules\MemberPortal\Application;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\NumberToWords;
use App\Modules\Documents\Application\TemplateRenderer;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\GuestPortal\Application\ManageService;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Application\BookingSiteRevenue;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's own bookings through the existing booking engine services (search, book, modify, cancel, pay link)
 * and the document engine (invoice and receipt PDFs). Every lookup is limited to the member's guest profile, so one
 * member can never reach another member's reservation, folio or invoice (404).
 */
class MemberBookings
{
    public function __construct(
        private HotelScope $scope, private ReservationService $reservations, private ManageService $manage,
        private Inventory $inventory, private Pricing $pricing, private DocumentRenderer $renderer,
    ) {}

    /** @return array{upcoming: array, past: array} */
    public function list(MemberAccount $a): array
    {
        $rows = Reservation::with('roomType:id,name', 'ratePlan:id,name', 'room:id,number')->where('guest_id', $a->guest_id)->orderByDesc('arrival')->limit(200)->get();
        $hotels = Hotel::whereIn('id', $rows->pluck('hotel_id')->unique())->get()->keyBy('id');
        $today = now()->toDateString();
        $all = $rows->map(fn (Reservation $r) => $this->summary($r, $hotels[$r->hotel_id] ?? null));
        $isUpcoming = fn ($r) => in_array($r['status'], ['tentative', 'confirmed', 'checked_in'], true) && $r['departure'] >= $today;

        return [
            'upcoming' => $all->filter($isUpcoming)->sortBy('arrival')->values()->all(),
            'past' => $all->reject($isUpcoming)->values()->all(),
        ];
    }

    public function summary(Reservation $r, ?Hotel $h): array
    {
        return [
            'number' => $r->number, 'reference' => $r->public_code, 'status' => $r->status, 'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(),
            'nights' => $r->nightCount(), 'adults' => $r->adults, 'children' => $r->children, 'total_amount' => (int) $r->total_amount, 'currency' => $r->currency,
            'deposit_required' => (int) $r->deposit_required, 'deposit_paid' => (int) $r->deposit_paid,
            'hotel' => $h ? $h->only(['code', 'name', 'city', 'phone']) : null,
            'room_type' => $r->roomType?->name, 'rate_plan' => $r->ratePlan?->name,
            'room' => $r->status === 'checked_in' ? $r->room?->number : null,
            'in_house' => $r->status === 'checked_in',
            'can_cancel' => in_array($r->status, ['tentative', 'confirmed'], true),
        ];
    }

    /**
     * The member's own reservation by booking reference (unique) or by number (404 for anyone else's). A number can repeat across
     * hotels, so when it matches several of the member's bookings the member is asked to use the reference.
     */
    public function find(MemberAccount $a, string $number): Reservation
    {
        $own = fn () => Reservation::with('guest', 'ratePlan')->where('guest_id', $a->guest_id);
        $byCode = $own()->where('public_code', strtoupper(trim($number)))->first();
        if ($byCode) {
            return $byCode;
        }
        $rows = $own()->where('number', $number)->limit(2)->get();
        if ($rows->count() > 1) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => 'Several of your bookings share this number. Open the booking from your list (booking reference).', 'code' => 'ambiguous_booking'], 422));
        }
        abort_if($rows->isEmpty(), 404);

        return $rows->first();
    }

    public function detail(MemberAccount $a, string $number): array
    {
        $r = $this->find($a, $number);
        $hotel = $this->scope->hotel($r->hotel_id);

        return $this->scope->run($hotel, function () use ($r, $hotel, $a) {
            $r->loadMissing('roomType', 'ratePlan', 'room');
            $upcoming = in_array($r->status, ['tentative', 'confirmed'], true);

            return $this->summary($r, $hotel) + [
                'modify' => $this->manage->modifyRule($r),
                'cancel' => ['allowed' => $upcoming, 'fee' => $upcoming ? $this->reservations->cancellationFee($r) : 0, 'currency' => $r->currency],
                'balance' => $this->manage->balance($r),
                'special_requests' => $r->special_requests,
                'check_in_time' => substr((string) $hotel->check_in_time, 0, 5), 'check_out_time' => substr((string) $hotel->check_out_time, 0, 5),
                'documents' => $this->documents($a, $r),
            ];
        });
    }

    public function modify(MemberAccount $a, string $number, string $arrival, string $departure, bool $apply): array
    {
        $r = $this->find($a, $number);

        return $this->scope->run($this->scope->hotel($r->hotel_id), fn () => $this->manage->modify($r, $arrival, $departure, $apply));
    }

    public function cancel(MemberAccount $a, string $number): array
    {
        $r = $this->find($a, $number);
        $fee = $this->scope->run($this->scope->hotel($r->hotel_id), fn () => $this->reservations->cancel($r, 'Cancelled by the member in the portal')->cancellation_fee);

        return ['status' => 'cancelled', 'cancellation_fee' => (int) $fee];
    }

    public function payLink(MemberAccount $a, string $number, ?int $amount): array
    {
        $r = $this->find($a, $number);

        return $this->scope->run($this->scope->hotel($r->hotel_id), fn () => ['url' => $this->manage->payLink($r, $amount)]);
    }

    // ------------------------------------------------------------------ documents

    /** Invoices (never company-billed ones) and folio statements of one of the member's stays. */
    public function documents(MemberAccount $a, Reservation $r): array
    {
        $folios = Folio::where('reservation_id', $r->id)->where('guest_id', $a->guest_id)->orderBy('id')->get(['id', 'number', 'status', 'balance', 'currency']);
        $invoices = Invoice::whereIn('folio_id', $folios->pluck('id'))->where('guest_id', $a->guest_id)->whereNull('company_id')->orderBy('id')
            ->get(['id', 'number', 'kind', 'total', 'paid', 'status', 'issue_date', 'currency']);

        return [
            'invoices' => $invoices->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'kind' => $i->kind, 'total' => (int) $i->total, 'paid' => (int) $i->paid,
                'status' => $i->status, 'issue_date' => $i->issue_date?->toDateString(), 'currency' => $i->currency])->all(),
            'receipts' => $folios->map(fn ($f) => ['id' => $f->id, 'number' => $f->number, 'status' => $f->status, 'balance' => (int) $f->balance, 'currency' => $f->currency])->all(),
        ];
    }

    public function invoicePdf(MemberAccount $a, int $id): Response
    {
        $invoice = Invoice::where('guest_id', $a->guest_id)->whereNull('company_id')->findOrFail($id);
        $hotel = $this->scope->hotel($invoice->hotel_id);

        return $this->scope->run($hotel, function () use ($invoice, $hotel) {
            $inv = $invoice->toArray();
            // The invoice is a legal document: always issued in French, like the staff PDF.
            $doc = $this->renderer->render('invoice', [
                'invoice' => $inv, 'words' => NumberToWords::convert((int) $inv['total'], 'fr'), 'net' => (int) $inv['total'] - (int) $inv['tax_total'],
                'templateNote' => TemplateRenderer::renderFor('invoice', 'fr', TemplateRenderer::values($hotel, null, null, $inv))['html'],
            ], [
                'view' => 'pdf.invoice', 'lang' => 'fr', 'hotel' => $hotel, 'source_type' => 'invoice', 'filename' => 'invoice-'.$inv['number'],
                'number' => $inv['number'], 'source_id' => $invoice->id, 'amount' => (int) $inv['total'], 'currency' => $inv['currency'] ?? 'XAF',
                'title' => (($inv['kind'] ?? 'invoice') === 'credit_note' ? 'Avoir' : 'Facture').' '.$inv['number'],
            ]);

            return $this->renderer->response($doc);
        });
    }

    public function receiptPdf(MemberAccount $a, int $id, string $lang): Response
    {
        $folio = Folio::where('guest_id', $a->guest_id)->whereNotNull('reservation_id')->findOrFail($id);
        $hotel = $this->scope->hotel($folio->hotel_id);

        return $this->scope->run($hotel, function () use ($folio, $hotel, $lang) {
            $f = $folio->load('lines')->toArray();
            $doc = $this->renderer->render('folio', ['folio' => $f], [
                'view' => 'pdf.folio', 'lang' => $lang, 'hotel' => $hotel, 'source_type' => 'folio', 'filename' => 'folio-'.$folio->number,
                'number' => (string) $folio->number, 'source_id' => $folio->id, 'amount' => (int) $folio->balance, 'currency' => $folio->currency ?? 'XAF',
                'title' => $lang === 'en' ? 'Folio statement' : 'Relevé de folio',
            ]);

            return $this->renderer->response($doc);
        });
    }

    // ------------------------------------------------------------------ search and book

    /** Availability and prices at the group's hotels. Members see members-only rates when their membership is active. */
    public function search(MemberAccount $a, array $d): array
    {
        $arrival = Carbon::parse($d['arrival'])->startOfDay();
        $departure = Carbon::parse($d['departure'])->startOfDay();
        $isMember = $a->membership()?->status === 'active';
        $hotels = Hotel::where('status', 'active')->when($d['hotel_code'] ?? null, fn ($q, $v) => $q->where('code', $v))->orderBy('name')->get();

        $out = [];
        foreach ($hotels as $hotel) {
            $offers = $this->scope->run($hotel, function () use ($hotel, $arrival, $departure, $d, $isMember) {
                $offers = [];
                foreach (RoomType::where('active', true)->orderBy('sort')->get() as $type) {
                    $grid = $this->inventory->grid($hotel->id, $type->id, $arrival->toDateString(), $departure->copy()->subDay()->toDateString());
                    $left = min(array_column($grid, 'available') ?: [0]);
                    if ($left < 1) {
                        continue;
                    }
                    $plans = RatePlan::where('active', true)->where('public', true)->when(! $isMember, fn ($q) => $q->where('members_only', false))->get();
                    foreach ($plans as $plan) {
                        try {
                            $q = $this->pricing->quote($plan, $type, $arrival, $departure, $d['adults'] ?? 1, $d['children'] ?? 0);
                        } catch (DomainException) {
                            continue;
                        }
                        $offers[] = [
                            'room_type' => $type->only(['id', 'code', 'name', 'max_adults', 'max_children', 'amenities']),
                            'rate_plan' => $plan->only(['id', 'code', 'name', 'meal_plan', 'refundable', 'cancellation_hours', 'members_only', 'deposit_required', 'deposit_percent']),
                            'rooms_left' => min($left, 5), 'total' => $q['total'], 'currency' => $hotel->currency, 'nights' => $q['nights'],
                        ];
                    }
                }

                return app(BookingSiteRevenue::class)->decorate($offers, $arrival, $departure, $d);
            });
            $out[] = ['hotel' => $hotel->only(['id', 'code', 'name', 'city']), 'offers' => $offers];
        }

        return ['arrival' => $d['arrival'], 'departure' => $d['departure'], 'member' => $isMember, 'results' => $out];
    }

    /** Book for the member's own profile (no guest details to retype). */
    public function book(MemberAccount $a, array $d): array
    {
        $hotel = Hotel::where('code', $d['hotel_code'])->where('status', 'active')->firstOrFail();

        $reservation = $this->scope->run($hotel, function () use ($a, $d) {
            $revenue = app(BookingSiteRevenue::class);
            $prepared = $revenue->prepare($d);
            $plan = $prepared['plan'];

            return DB::transaction(function () use ($a, $d, $plan, $revenue, $prepared) {
                $r = $this->reservations->create(array_merge([
                    'guest_id' => $a->guest_id, 'room_type_id' => $d['room_type_id'], 'rate_plan_id' => $plan->id,
                    'arrival' => $d['arrival'], 'departure' => $d['departure'], 'adults' => $d['adults'] ?? 1, 'children' => $d['children'] ?? 0,
                    'channel' => 'website', 'special_requests' => $d['special_requests'] ?? null,
                    'status' => $plan->deposit_required ? 'tentative' : 'confirmed', 'guarantee' => $plan->deposit_required ? 'deposit' : 'none',
                ], $prepared['fields']));
                $revenue->finish($r, $prepared);

                return $r;
            });
        });

        $payUrl = null;
        if ($reservation->deposit_required > 0) {
            try {
                $payUrl = $this->scope->run($hotel, fn () => $this->manage->payLink($reservation, null));
            } catch (DomainException) {
                $payUrl = null;
            }
        }

        return ['confirmation' => $reservation->number, 'reference' => $reservation->public_code, 'status' => $reservation->status, 'total' => (int) $reservation->total_amount, 'currency' => $reservation->currency,
            'deposit_required' => (int) $reservation->deposit_required, 'pay_url' => $payUrl];
    }
}

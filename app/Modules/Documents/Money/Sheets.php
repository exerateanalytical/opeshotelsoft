<?php

namespace App\Modules\Documents\Money;

use App\Models\User;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\FixedAsset;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Documents\Application\InternalApi;
use App\Modules\Documents\Application\NumberToWords;
use App\Modules\FrontOffice\Domain\CashierShift;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\Payments\Domain\PaymentLink;
use App\Modules\POS\Domain\Check;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\StoredEvent;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the structured "sheet" (title, meta, blocks) of every D-B money document from its source.
 * Each builder returns ['sheet' => [...], 'opts' => [engine options], 'guest_id' => ?int] and never writes anything.
 * Rendering is done by money/sheet.blade.php (or receipt80 for till receipts).
 */
class Sheets
{
    public string $currentLang = 'fr';

    private const NOT_MONEY = ['id', 'account', 'number', 'year', 'month', 'code', 'line_id', 'partner_id', 'level', 'rate', 'percent', 'occupancy_percent', 'days', 'count', 'life'];

    public function __construct(private InternalApi $api, private TenantContext $ctx) {}

    // ---------- helpers ----------

    public static function col(string $k, string $fr, string $en, string $t = 'text'): array
    {
        return ['k' => $k, 'fr' => $fr, 'en' => $en, 't' => $t];
    }

    private function hotel(): ?Hotel
    {
        return $this->ctx->hotelId() ? Hotel::find($this->ctx->hotelId()) : null;
    }

    private function user(?int $id): ?string
    {
        return $id ? User::withoutGlobalScopes()->whereKey($id)->value('name') : null;
    }

    private function methodName(?string $code): string
    {
        return (string) (PaymentMethod::where('code', $code)->value('name') ?: $code);
    }

    private function guestName(?int $id): ?string
    {
        return $id ? Guest::find($id)?->full_name : null;
    }

    public function guestLang(?int $guestId): ?string
    {
        $l = $guestId ? Guest::find($guestId)?->language : null;

        return in_array($l, ['fr', 'en'], true) ? $l : null;
    }

    private function words(int $amount): string
    {
        return NumberToWords::convert($amount, $this->currentLang);
    }

    private function date($v): string
    {
        return $v ? Carbon::parse($v)->format('d/m/Y') : '';
    }

    private function dt($v): string
    {
        return $v ? Carbon::parse($v)->setTimezone($this->hotel()?->timezone ?: 'Africa/Douala')->format('d/m/Y H:i') : '';
    }

    private function money($v): string
    {
        return number_format((int) $v, 0, ',', ' ');
    }

    // ---------- guest and front office ----------

    public function cancellation(int $id): array
    {
        $r = Reservation::findOrFail($id);
        if ($r->status !== 'cancelled') {
            throw new DomainException('Only a cancelled reservation has a cancellation confirmation.');
        }
        $refund = max(0, (int) $r->deposit_paid - (int) $r->cancellation_fee);

        return ['guest_id' => $r->guest_id, 'opts' => ['number' => 'ANN-'.$r->number, 'source_id' => $r->id, 'amount' => $refund, 'currency' => $r->currency],
            'sheet' => [
                'title' => ['Confirmation d\'annulation', 'Cancellation confirmation'],
                'meta' => [
                    ['Réservation', 'Reservation', $r->number], ['Client', 'Guest', $this->guestName($r->guest_id)],
                    ['Arrivée', 'Arrival', $this->date($r->arrival)], ['Départ', 'Departure', $this->date($r->departure)],
                    ['Type de chambre', 'Room type', RoomType::find($r->room_type_id)?->name],
                    ['Annulée le', 'Cancelled on', $this->dt($r->cancelled_at)], ['Motif', 'Reason', $r->cancellation_reason],
                ],
                'blocks' => [['type' => 'kv', 'title' => ['Montants', 'Amounts'], 'rows' => [
                    ['Acompte versé', 'Deposit paid', (int) $r->deposit_paid, 'money'],
                    ['Frais d\'annulation', 'Cancellation fee', (int) $r->cancellation_fee, 'money'],
                    ['Montant à rembourser', 'Amount to refund', $refund, 'money'],
                ]]],
                'note' => ['Nous confirmons l\'annulation de cette réservation. Nous espérons vous accueillir prochainement.', 'We confirm the cancellation of this reservation. We hope to welcome you soon.'],
            ]];
    }

    private function folioLineOf(int $id, array $types): FolioLine
    {
        $line = FolioLine::where('hotel_id', $this->ctx->requireHotelId())->findOrFail($id);
        if (! in_array($line->type, $types, true)) {
            throw new DomainException('This folio line is not a '.implode('/', $types).'.');
        }

        return $line;
    }

    /** $kind: payment_receipt | deposit_receipt */
    public function folioPayment(int $id, string $kind): array
    {
        $line = $this->folioLineOf($id, ['payment']);
        $folio = Folio::findOrFail($line->folio_id);
        $res = $folio->reservation_id ? Reservation::find($folio->reservation_id) : null;
        $amount = abs((int) $line->amount);
        $deposit = $kind === 'deposit_receipt';
        $number = ($deposit ? 'AC-' : 'RC-').$folio->number.'-'.$line->id;

        return ['guest_id' => $folio->guest_id, 'opts' => ['number' => $number, 'source_type' => 'folio_line', 'source_id' => $line->id, 'amount' => $amount, 'currency' => $folio->currency],
            'sheet' => [
                'title' => $deposit ? ['Reçu d\'acompte / prépaiement', 'Deposit / prepayment receipt'] : ['Reçu de paiement', 'Payment receipt'],
                'meta' => [
                    ['Date', 'Date', $this->date($line->business_date)], ['Reçu de', 'Received from', $folio->name],
                    ['Folio', 'Folio', $folio->number], ['Réservation', 'Reservation', $res?->number],
                    ['Arrivée prévue', 'Expected arrival', $deposit ? $this->date($res?->arrival) : null],
                    ['Mode de paiement', 'Payment method', $this->methodName($line->code)], ['Référence', 'Reference', $line->reference],
                    ['Encaissé par', 'Received by', $this->user($line->user_id)],
                ],
                'blocks' => [['type' => 'kv', 'rows' => [
                    ['Montant reçu', 'Amount received', $amount, 'money'], ['Devise', 'Currency', $folio->currency],
                    ['Solde du folio', 'Folio balance', (int) $folio->balance, 'money'],
                ]]],
                'words' => $this->words($amount),
                'note' => $deposit ? ['Cet acompte sera déduit de votre facture finale.', 'This deposit will be deducted from your final bill.'] : null,
                'sign' => [['Le client', 'Guest'], ['La caisse', 'Cashier']],
            ]];
    }

    public function posPayment(int $id): array
    {
        $p = DB::table('pos_payments')->where('hotel_id', $this->ctx->requireHotelId())->where('id', $id)->first() ?? abort(404);
        $c = Check::findOrFail($p->pos_check_id);

        return ['guest_id' => $c->guest_id, 'view' => 'documents::money.receipt80',
            'opts' => ['number' => 'RP-'.$c->number.'-'.$p->id, 'source_type' => 'pos_payment', 'source_id' => $p->id, 'amount' => (int) $p->amount, 'size' => '80mm'],
            'sheet' => [
                'title' => ['Reçu de paiement', 'Payment receipt'], 'currency' => 'XAF',
                'meta' => [['Ticket', 'Check', $c->number], ['Date', 'Date', $this->dt($p->created_at)], ['Mode', 'Method', $this->methodName($p->method_code)], ['Référence', 'Reference', $p->reference]],
                'lines' => [], 'totals' => [['Montant', 'Amount', (int) $p->amount, true], ['Remis', 'Tendered', (int) $p->tendered], ['Rendu', 'Change', (int) $p->change_given]],
            ]];
    }

    public function intentReceipt(int $id): array
    {
        $i = PaymentIntent::findOrFail($id);
        if ($i->status !== 'succeeded') {
            throw new DomainException('Only a succeeded payment has a receipt.');
        }
        $folio = $i->folio_id ? Folio::find($i->folio_id) : null;
        $link = $i->folio_id ? PaymentLink::where('target_type', 'folio')->where('target_id', $i->folio_id)->first() : null;

        return ['guest_id' => $folio?->guest_id, 'opts' => ['number' => 'RE-'.strtoupper(substr((string) $i->reference, 0, 8)), 'source_id' => $i->id, 'amount' => (int) $i->amount, 'currency' => $i->currency],
            'sheet' => [
                'title' => ['Reçu de paiement en ligne', 'Online payment receipt'],
                'meta' => [
                    ['Date', 'Date', $this->dt($i->completed_at ?? $i->created_at)], ['Passerelle', 'Gateway', $i->provider],
                    ['Référence', 'Reference', $i->provider_reference ?: $i->reference], ['Folio', 'Folio', $folio?->number],
                    ['Lien de paiement', 'Pay link', $link?->code], ['Payeur', 'Payer', $i->payer_phone ?: $i->payer_email],
                ],
                'blocks' => [['type' => 'kv', 'rows' => [
                    ['Montant payé', 'Amount paid', (int) $i->amount, 'money'], ['Déjà remboursé', 'Already refunded', (int) $i->refunded_amount, 'money'],
                ]]],
                'words' => $this->words((int) $i->amount),
            ]];
    }

    public function linkReceipt(int $id): array
    {
        $l = PaymentLink::findOrFail($id);
        $rows = $l->intents()->where('status', 'succeeded')->get()
            ->map(fn ($i) => ['date' => $i->completed_at, 'ref' => $i->provider_reference ?: $i->reference, 'provider' => $i->provider, 'amount' => (int) $i->amount])->all();

        return ['guest_id' => null, 'opts' => ['number' => 'RL-'.$l->code, 'source_id' => $l->id, 'amount' => (int) $l->amount_paid, 'currency' => $l->currency],
            'sheet' => [
                'title' => ['Reçu de lien de paiement', 'Pay-link receipt'],
                'meta' => [['Lien', 'Link', $l->code], ['Objet', 'For', $l->label ?: $l->target_type.' #'.$l->target_id], ['Statut', 'Status', $l->status], ['Montant demandé', 'Amount requested', $this->money($l->amount)]],
                'blocks' => [['type' => 'table', 'title' => ['Paiements reçus', 'Payments received'], 'cols' => [
                    self::col('date', 'Date', 'Date', 'datetime'), self::col('provider', 'Passerelle', 'Gateway'), self::col('ref', 'Référence', 'Reference'), self::col('amount', 'Montant', 'Amount', 'money'),
                ], 'rows' => $rows, 'totals' => [['Total payé', 'Total paid', (int) $l->amount_paid]]]],
            ]];
    }

    public function proforma(int $folioId): array
    {
        $f = Folio::findOrFail($folioId);
        $charges = $f->lines()->where('type', 'charge')->where('reversed', false)->get();
        $tax = (int) $charges->sum('tax_amount');
        $total = (int) $charges->sum('amount');
        $paid = -(int) $f->lines()->whereIn('type', ['payment', 'refund'])->sum('amount');

        return ['guest_id' => $f->guest_id, 'opts' => ['number' => 'PF-'.$f->number, 'source_id' => $f->id, 'amount' => $total, 'currency' => $f->currency],
            'sheet' => [
                'title' => ['Facture pro forma', 'Pro-forma invoice'], 'subtitle' => ['Document non fiscal : ne vaut pas facture.', 'Not a tax document: this is not an invoice.'],
                'meta' => [['Client', 'Bill to', $f->name], ['Folio', 'Folio', $f->number], ['Date', 'Date', $this->date($this->hotel()?->business_date)], ['Devise', 'Currency', $f->currency]],
                'blocks' => [['type' => 'table', 'cols' => [
                    self::col('business_date', 'Date', 'Date', 'date'), self::col('description', 'Désignation', 'Description'), self::col('amount', 'Montant TTC', 'Amount incl. tax', 'money'), self::col('tax_amount', 'dont taxes', 'of which tax', 'money'),
                ], 'rows' => $charges->map(fn ($l) => $l->only(['business_date', 'description', 'amount', 'tax_amount']))->values()->all(),
                    'totals' => [['Hors taxes', 'Net', $total - $tax], ['Taxes', 'Taxes', $tax], ['Total estimé TTC', 'Estimated total', $total], ['Déjà réglé', 'Already paid', $paid], ['Reste à payer', 'Balance due', $total - $paid]]]],
            ]];
    }

    public function expressCheckout(int $reservationId): array
    {
        $r = Reservation::findOrFail($reservationId);
        $f = Folio::where('reservation_id', $r->id)->where('kind', 'guest')->orderBy('id')->firstOrFail();
        $by = $f->lines()->where('type', 'charge')->where('reversed', false)->get()->groupBy('department')
            ->map(fn ($g, $d) => ['department' => $d ?: '-', 'amount' => (int) $g->sum('amount'), 'tax' => (int) $g->sum('tax_amount')])->values()->all();
        $pay = $f->lines()->whereIn('type', ['payment', 'refund'])->get()->groupBy('code')
            ->map(fn ($g, $c) => ['method' => $this->methodName((string) $c), 'amount' => -(int) $g->sum('amount')])->values()->all();
        $room = $r->room_id ? Room::find($r->room_id)?->number : null;
        $nights = max(1, (int) Carbon::parse($r->arrival)->diffInDays(Carbon::parse($r->departure)));

        return ['guest_id' => $r->guest_id, 'opts' => ['number' => 'CO-'.$r->number, 'source_id' => $r->id, 'amount' => abs((int) $f->balance), 'currency' => $f->currency],
            'sheet' => [
                'title' => ['Récapitulatif de départ express', 'Express check-out summary'],
                'meta' => [['Client', 'Guest', $this->guestName($r->guest_id)], ['Réservation', 'Reservation', $r->number], ['Chambre', 'Room', $room],
                    ['Séjour', 'Stay', $this->date($r->arrival).' - '.$this->date($r->departure).' ('.$nights.')'],
                    ['Départ effectif', 'Checked out', $this->dt($r->checked_out_at)], ['Folio', 'Folio', $f->number]],
                'blocks' => [
                    ['type' => 'table', 'title' => ['Charges par département', 'Charges by department'], 'cols' => [self::col('department', 'Département', 'Department'), self::col('amount', 'Montant', 'Amount', 'money'), self::col('tax', 'dont taxes', 'of which tax', 'money')],
                        'rows' => $by, 'totals' => [['Total charges', 'Total charges', array_sum(array_column($by, 'amount'))]]],
                    ['type' => 'table', 'title' => ['Règlements', 'Payments'], 'cols' => [self::col('method', 'Mode', 'Method'), self::col('amount', 'Montant', 'Amount', 'money')], 'rows' => $pay, 'totals' => [['Solde', 'Balance', (int) $f->balance]]],
                ],
                'note' => ['Une facture détaillée est disponible sur demande. Merci de votre séjour.', 'A detailed invoice is available on request. Thank you for staying with us.'],
            ]];
    }

    public function creditNote(int $id): array
    {
        $n = Invoice::where('kind', 'credit_note')->findOrFail($id);
        $orig = $n->credits_invoice_id ? Invoice::find($n->credits_invoice_id) : null;
        $net = (int) $n->total - (int) $n->tax_total;

        return ['guest_id' => null, 'lang' => 'fr', 'opts' => ['number' => $n->number, 'source_type' => 'credit_note', 'source_id' => $n->id, 'amount' => abs((int) $n->total), 'currency' => $n->currency, 'title' => 'Avoir '.$n->number],
            'sheet' => [
                'title' => ['Avoir', 'Credit note'],
                'meta' => [['Client', 'Customer', $n->bill_to_name], ['NIU client', 'Customer tax ID', $n->bill_to_tax_id], ['Adresse', 'Address', $n->bill_to_address],
                    ['Date', 'Date', $this->date($n->issue_date)], ['Facture d\'origine', 'Original invoice', $orig?->number], ['Motif', 'Reason', $n->lines[0]['reason'] ?? null]],
                'blocks' => [['type' => 'table', 'cols' => [self::col('description', 'Désignation', 'Description'), self::col('amount', 'Montant TTC', 'Amount', 'money'), self::col('tax_amount', 'dont taxes', 'tax', 'money')],
                    'rows' => $n->lines, 'totals' => [['Total HT', 'Net', $net], ['Total taxes', 'Taxes', (int) $n->tax_total], ['Total avoir TTC', 'Total credit', (int) $n->total]]]],
                'words' => NumberToWords::convert(abs((int) $n->total), 'fr'),
                'note' => ['Cet avoir annule ou corrige la facture indiquée.', 'This credit note cancels or corrects the invoice shown.'],
            ]];
    }

    public function refundVoucher(int $id): array
    {
        $line = $this->folioLineOf($id, ['refund']);
        $f = Folio::findOrFail($line->folio_id);
        $amount = abs((int) $line->amount);

        return ['guest_id' => $f->guest_id, 'opts' => ['number' => 'RB-'.$f->number.'-'.$line->id, 'source_type' => 'folio_line', 'source_id' => $line->id, 'amount' => $amount, 'currency' => $f->currency],
            'sheet' => [
                'title' => ['Bon de remboursement', 'Refund voucher'],
                'meta' => [['Date', 'Date', $this->date($line->business_date)], ['Bénéficiaire', 'Paid to', $f->name], ['Folio', 'Folio', $f->number], ['Mode', 'Method', $this->methodName($line->code)],
                    ['Motif', 'Reason', $line->reason], ['Autorisé par', 'Authorised by', $this->user($line->user_id)]],
                'blocks' => [['type' => 'kv', 'rows' => [['Montant remboursé', 'Amount refunded', $amount, 'money'], ['Devise', 'Currency', $f->currency]]]],
                'words' => $this->words($amount),
                'sign' => [['Le bénéficiaire', 'Beneficiary'], ['Le caissier', 'Cashier'], ['Le responsable', 'Manager']],
            ]];
    }

    public function guestStatement(int $guestId, ?string $from, ?string $to): array
    {
        $g = Guest::findOrFail($guestId);
        $folios = Folio::where('guest_id', $g->id)->orderBy('id')->get();
        $lines = FolioLine::whereIn('folio_id', $folios->pluck('id'))->when($from, fn ($q) => $q->where('business_date', '>=', $from))->when($to, fn ($q) => $q->where('business_date', '<=', $to))
            ->whereNotIn('type', ['transfer_in', 'transfer_out'])->orderBy('business_date')->orderBy('id')->get();
        $nums = $folios->pluck('number', 'id');
        $run = 0;
        $rows = $lines->map(function ($l) use (&$run, $nums) {
            $run += (int) $l->amount;

            return ['date' => $l->business_date, 'folio' => $nums[$l->folio_id] ?? '', 'description' => $l->description, 'debit' => $l->amount > 0 ? (int) $l->amount : null, 'credit' => $l->amount < 0 ? -(int) $l->amount : null, 'balance' => $run];
        })->all();

        return ['guest_id' => $g->id, 'opts' => ['number' => 'RG-'.$g->id.'-'.now()->format('ymdHis'), 'source_type' => 'guest_statement', 'currency' => 'XAF'],
            'sheet' => [
                'title' => ['Relevé de compte client', 'Guest statement of account'],
                'meta' => [['Client', 'Guest', $g->full_name], ['Période', 'Period', ($from ? $this->date($from) : '...').' - '.($to ? $this->date($to) : $this->date(now()))], ['Édité le', 'Printed', $this->date(now())]],
                'blocks' => [['type' => 'table', 'cols' => [self::col('date', 'Date', 'Date', 'date'), self::col('folio', 'Folio', 'Folio'), self::col('description', 'Libellé', 'Description'),
                    self::col('debit', 'Débit', 'Charges', 'money'), self::col('credit', 'Crédit', 'Credits', 'money'), self::col('balance', 'Solde', 'Balance', 'money')], 'rows' => $rows, 'totals' => [['Solde dû', 'Balance due', $run]]]],
            ]];
    }

    public function roomMove(int $reservationId, ?int $eventId): array
    {
        $r = Reservation::findOrFail($reservationId);
        $ev = StoredEvent::where('type', 'frontoffice.room_moved')->where('aggregate_id', (string) $r->id)
            ->when($eventId, fn ($q, $v) => $q->where('id', $v))->orderByDesc('id')->first() ?? abort(404, 'No room move recorded.');
        $p = $ev->payload;

        return ['guest_id' => $r->guest_id, 'opts' => ['number' => 'MC-'.$r->number.'-'.$ev->id, 'source_id' => $ev->id],
            'sheet' => [
                'title' => ['Bon de changement de chambre', 'Room-move slip'],
                'meta' => [['Client', 'Guest', $this->guestName($r->guest_id)], ['Réservation', 'Reservation', $r->number], ['Date et heure', 'Date and time', $this->dt($ev->created_at)],
                    ['Ancienne chambre', 'From room', $p['from_room'] ?? ''], ['Nouvelle chambre', 'To room', $p['to_room'] ?? ''], ['Motif', 'Reason', $p['reason'] ?? ''], ['Séjour', 'Stay', $this->date($r->arrival).' - '.$this->date($r->departure)]],
                'blocks' => [],
                'note' => ['Merci de remettre les clés de l\'ancienne chambre à la réception.', 'Please return the keys of the previous room to reception.'],
                'sign' => [['Le client', 'Guest'], ['La réception', 'Front desk'], ['Gouvernante', 'Housekeeping']],
            ]];
    }

    // ---------- cashier and POS ----------

    public function posReceipt(int $id): array
    {
        $c = Check::findOrFail($id);
        $lines = $c->lines()->get();
        $pays = $c->payments()->get();
        $settled = $c->status === 'settled';

        return ['guest_id' => $c->guest_id, 'view' => 'documents::money.receipt80', 'issue' => $settled || $c->status === 'void',
            'opts' => ['number' => 'T-'.$c->number, 'source_type' => 'pos_check', 'source_id' => $c->id, 'amount' => (int) $c->total, 'size' => '80mm', 'draft' => ! $settled],
            'sheet' => [
                'title' => ['Ticket de caisse', 'Receipt'], 'currency' => $this->hotel()?->currency ?? 'XAF',
                'status_note' => $c->status === 'void' ? ['ANNULÉ', 'VOID'] : ($settled ? null : ['NON RÉGLÉ', 'UNPAID']),
                'meta' => [['Ticket', 'Check', $c->number], ['Table', 'Table', $c->table_number], ['Couverts', 'Covers', (string) $c->covers], ['Date', 'Date', $this->dt($c->settled_at ?? $c->created_at)], ['Serveur', 'Server', $this->user($c->opened_by)]],
                'lines' => $lines->map(fn ($l) => ['qty' => $l->quantity, 'name' => $l->name, 'amount' => $l->amount, 'void' => $l->voided])->all(),
                'totals' => array_merge(
                    [['Sous-total', 'Subtotal', (int) $c->subtotal]],
                    $c->discount > 0 ? [['Remise', 'Discount', -(int) $c->discount]] : [],
                    $c->comp_total > 0 ? [['Offert', 'Comp', -(int) $c->comp_total]] : [],
                    [['TOTAL', 'TOTAL', (int) $c->total, true]],
                    $c->settlement === 'room_charge' ? [['Porté sur chambre', 'Charged to room', (int) $c->total]] : [],
                    $pays->map(fn ($p) => [$this->methodName($p->method_code), $this->methodName($p->method_code), (int) $p->amount])->all(),
                    $pays->sum('change_given') > 0 ? [['Rendu', 'Change', (int) $pays->sum('change_given')]] : [],
                ),
                'note' => ['Merci de votre visite.', 'Thank you for your visit.'],
            ]];
    }

    public function shiftReport(int $id): array
    {
        $s = CashierShift::findOrFail($id);
        $closed = $s->status === 'closed';
        $expected = $s->expected ?: app(\App\Modules\FrontOffice\Application\CashierService::class)->expected($s);
        $counted = $s->counted ?: [];
        $rows = collect(array_unique(array_merge(array_keys($expected), array_keys($counted))))->map(fn ($c) => [
            'method' => $this->methodName((string) $c), 'expected' => (int) ($expected[$c] ?? 0), 'counted' => $closed ? (int) ($counted[$c] ?? 0) : null,
            'variance' => $closed ? (int) ($counted[$c] ?? 0) - (int) ($expected[$c] ?? 0) : null,
        ])->values()->all();
        $folio = FolioLine::where('cashier_shift_id', $s->id)->whereIn('type', ['payment', 'refund', 'reversal'])->selectRaw('type, count(*) n, -sum(amount) total')->groupBy('type')->get();
        $pos = DB::table('pos_payments')->where('cashier_shift_id', $s->id)->selectRaw('count(*) n, sum(amount) total')->first();
        $activity = $folio->map(fn ($r) => ['Folio : '.$r->type, 'Folio: '.$r->type, $r->n.' / '.$this->money($r->total)])->all();
        $activity[] = ['Paiements POS', 'POS payments', ((int) ($pos->n ?? 0)).' / '.$this->money($pos->total ?? 0)];

        return ['guest_id' => null, 'opts' => ['number' => 'Z-'.$s->business_date->format('Ymd').'-'.$s->id, 'source_id' => $s->id],
            'sheet' => [
                'title' => ['Rapport de caisse (Z)', 'Cashier shift report (Z)'],
                'status_note' => $closed ? null : ['SESSION OUVERTE - rapport provisoire', 'SHIFT STILL OPEN - interim report'],
                'meta' => [['Caissier', 'Cashier', $this->user($s->user_id)], ['Poste', 'Station', $s->station], ['Journée comptable', 'Business date', $this->date($s->business_date)],
                    ['Ouverture', 'Opened', $this->dt($s->opened_at)], ['Clôture', 'Closed', $this->dt($s->closed_at)], ['Fond de caisse', 'Opening float', $this->money($s->opening_float)]],
                'blocks' => [
                    ['type' => 'table', 'title' => ['Encaissements par mode', 'Takings by method'], 'cols' => [self::col('method', 'Mode', 'Method'), self::col('expected', 'Attendu', 'Expected', 'money'), self::col('counted', 'Compté', 'Counted', 'money'), self::col('variance', 'Écart', 'Variance', 'money')],
                        'rows' => $rows, 'totals' => $closed ? [['Écart total', 'Total variance', (int) $s->variance]] : []],
                    ['type' => 'kv', 'title' => ['Activité', 'Activity'], 'rows' => $activity],
                ],
                'sign' => [['Le caissier', 'Cashier'], ['Le superviseur', 'Supervisor']],
            ]];
    }

    public function voidSlipFolio(int $id): array
    {
        $rev = $this->folioLineOf($id, ['reversal']);
        $orig = $rev->reverses_line_id ? FolioLine::find($rev->reverses_line_id) : null;
        $f = Folio::findOrFail($rev->folio_id);

        return ['guest_id' => $f->guest_id, 'opts' => ['number' => 'VD-'.$f->number.'-'.$rev->id, 'source_type' => 'folio_line', 'source_id' => $rev->id, 'amount' => abs((int) $rev->amount), 'currency' => $f->currency],
            'sheet' => [
                'title' => ['Autorisation d\'annulation', 'Void authorisation slip'],
                'meta' => [['Folio', 'Folio', $f->number], ['Date', 'Date', $this->date($rev->business_date)], ['Ligne annulée', 'Voided line', $orig?->description], ['Montant', 'Amount', $this->money(abs((int) $rev->amount))],
                    ['Motif', 'Reason', $rev->reason], ['Autorisé par', 'Authorised by', $this->user($rev->user_id)], ['Heure', 'Time', $this->dt($rev->created_at)]],
                'blocks' => [], 'sign' => [['Demandeur', 'Requested by'], ['Responsable', 'Authorised by']],
            ]];
    }

    public function voidSlipPos(int $id): array
    {
        $c = Check::findOrFail($id);
        $rows = $c->lines()->where(fn ($q) => $q->where('voided', true)->orWhereNotNull('comp_status'))->get()->map(fn ($l) => [
            'name' => $l->name, 'amount' => (int) $l->amount, 'kind' => $l->voided ? 'void' : 'comp: '.$l->comp_status, 'reason' => $l->void_reason ?: $l->comp_reason,
            'by' => $this->user($l->voided_by ?: $l->comp_approved_by),
        ])->all();
        if (! $rows && $c->status !== 'void') {
            abort(404, 'Nothing voided or comped on this check.');
        }

        return ['guest_id' => null, 'opts' => ['number' => 'VP-'.$c->number, 'source_type' => 'pos_void', 'source_id' => $c->id, 'amount' => (int) $c->comp_total],
            'sheet' => [
                'title' => ['Autorisation d\'annulation / offert (POS)', 'POS void / comp authorisation'],
                'meta' => [['Ticket', 'Check', $c->number], ['Date', 'Date', $this->date($c->business_date)], ['Statut', 'Status', $c->status], ['Motif d\'annulation du ticket', 'Check void reason', $c->void_reason]],
                'blocks' => [['type' => 'table', 'cols' => [self::col('name', 'Article', 'Item'), self::col('amount', 'Montant', 'Amount', 'money'), self::col('kind', 'Type', 'Type'), self::col('reason', 'Motif', 'Reason'), self::col('by', 'Autorisé par', 'Authorised by')], 'rows' => $rows]],
                'sign' => [['Serveur', 'Server'], ['Superviseur', 'Supervisor']],
            ]];
    }

    // ---------- accounting ----------

    public function companyStatement(int $companyId): array
    {
        $co = Company::findOrFail($companyId);
        $inv = Invoice::where('company_id', $co->id)->orderBy('issue_date')->orderBy('id')->get();
        $today = $this->hotel()?->business_date ?? now();
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $rows = $inv->map(function ($i) use (&$buckets, $today) {
            $credit = $i->kind === 'credit_note';
            $out = in_array($i->status, ['cancelled', 'paid'], true) ? 0 : ($credit ? -abs((int) $i->total) : (int) $i->total - (int) $i->paid);
            if (! $credit && in_array($i->status, ['issued', 'partially_paid'], true)) {
                $d = $i->due_date ? (int) Carbon::parse($i->due_date)->diffInDays($today, false) : 0;
                $buckets[match (true) { $d <= 0 => 'current', $d <= 30 => '1-30', $d <= 60 => '31-60', $d <= 90 => '61-90', default => '90+' }] += $out;
            }

            return ['number' => $i->number, 'issue_date' => $i->issue_date, 'due_date' => $i->due_date, 'total' => (int) $i->total, 'paid' => (int) $i->paid, 'outstanding' => $out, 'status' => $i->status];
        })->all();
        $balance = (int) Folio::where('company_id', $co->id)->where('kind', 'city_ledger')->sum('balance');

        return ['guest_id' => null, 'opts' => ['number' => 'RS-'.$co->id.'-'.now()->format('ymdHis'), 'source_type' => 'company_statement', 'amount' => $balance, 'currency' => 'XAF'],
            'sheet' => [
                'title' => ['Relevé de compte société (compte client)', 'Company statement of account (city ledger)'],
                'meta' => [['Société', 'Company', $co->name], ['NIU', 'Tax ID', $co->tax_id], ['Adresse', 'Address', $co->address], ['Conditions', 'Terms', $co->payment_terms_days.' j'], ['Limite de crédit', 'Credit limit', $this->money($co->credit_limit)], ['Édité le', 'Printed', $this->date(now())]],
                'blocks' => [
                    ['type' => 'table', 'cols' => [self::col('number', 'Facture', 'Invoice'), self::col('issue_date', 'Émise', 'Issued', 'date'), self::col('due_date', 'Échéance', 'Due', 'date'), self::col('total', 'Total', 'Total', 'money'), self::col('paid', 'Réglé', 'Paid', 'money'), self::col('outstanding', 'Reste dû', 'Outstanding', 'money'), self::col('status', 'Statut', 'Status')],
                        'rows' => $rows, 'totals' => [['Solde du compte', 'Account balance', $balance]]],
                    ['type' => 'kv', 'title' => ['Balance âgée', 'Ageing'], 'rows' => collect($buckets)->map(fn ($v, $k) => [$k, $k, $v, 'money'])->values()->all()],
                ],
            ]];
    }

    public function reminder(int $invoiceId, int $level, string $lang): array
    {
        $level = max(1, min(3, $level));
        $i = Invoice::findOrFail($invoiceId);
        $out = (int) $i->total - (int) $i->paid;
        if ($i->kind === 'credit_note' || $out <= 0 || ! in_array($i->status, ['issued', 'partially_paid'], true)) {
            throw new DomainException('This invoice has nothing outstanding to remind.');
        }
        $hid = $this->ctx->requireHotelId();
        $days = $i->due_date ? max(0, (int) Carbon::parse($i->due_date)->diffInDays($this->hotel()?->business_date ?? now(), false)) : 0;
        $cfg = Schema::hasTable('dunning_levels') ? DB::table('dunning_levels')->where('hotel_id', $hid)->where('level', $level)->first() : null;
        $notice = Schema::hasTable('dunning_notices') ? DB::table('dunning_notices')->where('hotel_id', $hid)->where('invoice_id', $i->id)->where('level', $level)->orderByDesc('id')->first() : null;
        $fee = (int) ($notice->fee ?? $cfg->fee ?? \App\Modules\Accounting\Payables\Dunning::DEFAULTS[$level]['fee']);
        $interest = (int) ($notice->interest ?? 0);
        $body = $notice && $notice->locale === $lang ? $notice->body : ($cfg->{'body_'.$lang} ?? null);
        $co = $i->company_id ? Company::find($i->company_id) : null;
        $tones = [
            1 => ['Rappel de paiement', 'Payment reminder', 'Sauf erreur de notre part, la facture ci-dessous n\'a pas été réglée à son échéance. Nous vous remercions de bien vouloir procéder à son règlement.', 'Unless we are mistaken, the invoice below was not paid by its due date. Please arrange payment.'],
            2 => ['Deuxième rappel', 'Second reminder', 'Malgré notre précédent rappel, la facture ci-dessous reste impayée. Merci de régulariser sans délai.', 'Despite our earlier reminder, the invoice below remains unpaid. Please settle without delay.'],
            3 => ['Mise en demeure avant poursuites', 'Final notice before collection', 'Cette facture demeure impayée. À défaut de règlement sous 8 jours, nous engagerons le recouvrement par toute voie de droit.', 'This invoice remains unpaid. Failing payment within 8 days we will start collection proceedings.'],
        ][$level];

        return ['guest_id' => null, 'type' => 'payment_reminder',
            'opts' => ['number' => 'REL'.$level.'-'.$i->number, 'source_type' => 'reminder_'.$level, 'source_id' => $i->id, 'amount' => $out + $fee + $interest, 'currency' => $i->currency],
            'sheet' => [
                'title' => [$tones[0], $tones[1]], 'subtitle' => ['Niveau '.$level.' / 3', 'Level '.$level.' of 3'],
                'meta' => [['Destinataire', 'To', $i->bill_to_name], ['NIU', 'Tax ID', $i->bill_to_tax_id], ['Contact', 'Contact', $co?->contact_name], ['Date', 'Date', $this->date($this->hotel()?->business_date)]],
                'blocks' => array_values(array_filter([
                    ['type' => 'text', 'text' => [$tones[2], $tones[3]]],
                    $body ? ['type' => 'text', 'text' => [$body, $body]] : null,
                    ['type' => 'kv', 'rows' => [['Facture', 'Invoice', $i->number], ['Date d\'émission', 'Issue date', $this->date($i->issue_date)], ['Échéance', 'Due date', $this->date($i->due_date)], ['Jours de retard', 'Days overdue', (string) $days],
                        ['Montant de la facture', 'Invoice amount', (int) $i->total, 'money'], ['Déjà réglé', 'Already paid', (int) $i->paid, 'money'], ['Solde dû', 'Balance due', $out, 'money'],
                        ['Frais de relance (créance)', 'Reminder fee (claim)', $fee, 'money'], ['Intérêts de retard (créance)', 'Late interest (claim)', $interest, 'money'], ['Total réclamé', 'Total claimed', $out + $fee + $interest, 'money']]],
                ])),
                'sign' => [['Le service comptabilité', 'Accounts department']],
            ]];
    }

    public function journalVoucher(int $id): array
    {
        $e = JournalEntry::with('lines')->findOrFail($id);
        $names = Account::pluck('name', 'code');
        $rows = $e->lines->map(fn ($l) => ['account' => $l->account_code, 'name' => $names[$l->account_code] ?? '', 'description' => $l->description, 'partner' => $l->partner_type ? $l->partner_type.' #'.$l->partner_id : null, 'debit' => (int) $l->debit ?: null, 'credit' => (int) $l->credit ?: null])->all();

        return ['guest_id' => null, 'opts' => ['number' => 'PC-'.$e->number, 'source_type' => 'journal_entry', 'source_id' => $e->id, 'amount' => (int) $e->total],
            'sheet' => [
                'title' => ['Pièce comptable', 'Journal voucher'],
                'status_note' => $e->reversed ? ['PIÈCE EXTOURNÉE', 'ENTRY REVERSED'] : ($e->reverses_entry_id ? ['EXTOURNE', 'REVERSAL ENTRY'] : null),
                'meta' => [['N° écriture', 'Entry no.', $e->number], ['Journal', 'Journal', $e->journal_code], ['Date', 'Date', $this->date($e->date)], ['Libellé', 'Description', $e->description], ['Origine', 'Source', $e->source_type ? $e->source_type.' '.$e->source_id : null], ['Saisi par', 'Posted by', $this->user($e->created_by)], ['Approuvé par', 'Approved by', $this->user($e->approved_by)]],
                'blocks' => [['type' => 'table', 'cols' => [self::col('account', 'Compte', 'Account'), self::col('name', 'Intitulé', 'Name'), self::col('description', 'Libellé', 'Description'), self::col('partner', 'Tiers', 'Partner'), self::col('debit', 'Débit', 'Debit', 'money'), self::col('credit', 'Crédit', 'Credit', 'money')],
                    'rows' => $rows, 'totals' => [['Total débit = crédit', 'Total debit = credit', (int) $e->total]]]],
                'sign' => [['Préparé par', 'Prepared by'], ['Vérifié par', 'Checked by'], ['Approuvé par', 'Approved by']],
            ]];
    }

    public function supplierPayment(int $id): array
    {
        $p = DB::table('supplier_payments')->where('hotel_id', $this->ctx->requireHotelId())->where('id', $id)->first() ?? abort(404);
        $s = Supplier::find($p->supplier_id);
        $inv = DB::table('supplier_invoices')->where('id', $p->supplier_invoice_id)->first();
        $names = Account::pluck('name', 'code');

        return ['guest_id' => null, 'opts' => ['number' => 'PV-'.str_pad((string) $p->id, 6, '0', STR_PAD_LEFT), 'source_type' => 'supplier_payment', 'source_id' => $p->id, 'amount' => (int) $p->amount, 'currency' => 'XAF'],
            'sheet' => [
                'title' => ['Bon de paiement fournisseur et avis de règlement', 'Supplier payment voucher and remittance advice'],
                'meta' => [['Fournisseur', 'Supplier', $s?->name], ['NIU', 'Tax ID', $s?->tax_id], ['Date', 'Date', $this->date($p->date)], ['Compte de règlement', 'Paid from', $p->account.' '.($names[$p->account] ?? '')], ['Référence', 'Reference', $p->reference], ['Mode préféré', 'Preferred method', $s?->payment_method], ['Compte bancaire / MoMo', 'Bank / MoMo', $s?->bank_account ?: $s?->momo_number]],
                'blocks' => [
                    ['type' => 'table', 'title' => ['Facture réglée', 'Invoice settled'], 'cols' => [self::col('reference', 'Réf. facture', 'Invoice ref'), self::col('date', 'Date', 'Date', 'date'), self::col('total', 'Total', 'Total', 'money'), self::col('paid', 'Réglé à ce jour', 'Paid to date', 'money'), self::col('this', 'Ce paiement', 'This payment', 'money')],
                        'rows' => [['reference' => $inv->reference ?? '', 'date' => $inv->date ?? null, 'total' => (int) ($inv->total ?? 0), 'paid' => (int) ($inv->paid ?? 0), 'this' => (int) $p->amount]], 'totals' => [['Montant payé', 'Amount paid', (int) $p->amount]]],
                ],
                'words' => $this->words((int) $p->amount),
                'note' => ['Avis de règlement : nous avons réglé le montant ci-dessus. Merci de rapprocher ce paiement avec votre facture.', 'Remittance advice: we have paid the amount above. Please match this payment to your invoice.'],
                'sign' => [['Préparé par', 'Prepared by'], ['Approuvé par', 'Approved by'], ['Reçu par le fournisseur', 'Received by supplier']],
            ]];
    }

    // ---------- dated reports (generic flatten of the endpoint answer) ----------

    /** Turn any endpoint JSON into blocks: scalars become a key/value block, lists of rows tables, nested maps recurse. */
    public function flatten(array $data, string $title = '', int $depth = 0): array
    {
        $blocks = [];
        $scalars = [];
        foreach ($data as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $scalars[] = [$this->label((string) $k, 'fr'), $this->label((string) $k, 'en'), $v, $this->kind((string) $k, $v)];
            }
        }
        if ($scalars) {
            $blocks[] = ['type' => 'kv', 'title' => $title ? [$title, $title] : null, 'rows' => $scalars];
        }
        foreach ($data as $k => $v) {
            if (! is_array($v) || ! $v) {
                continue;
            }
            $lbl = [$this->label((string) $k, 'fr'), $this->label((string) $k, 'en')];
            if (array_is_list($v) && is_array($v[0] ?? null) && ! array_is_list($v[0])) {
                $keys = collect($v)->flatMap(fn ($r) => array_keys(array_filter((array) $r, fn ($x) => is_scalar($x) || $x === null)))->unique()->values()->all();
                $keys = array_values(array_diff($keys, ['hotel_id', 'group_id', 'created_at', 'updated_at']));
                $cols = array_map(fn ($c) => self::col($c, $this->label($c, 'fr'), $this->label($c, 'en'), $this->kind($c, collect($v)->pluck($c)->first(fn ($x) => $x !== null))), $keys);
                $blocks[] = ['type' => 'table', 'title' => $lbl, 'cols' => $cols, 'rows' => $v];
            } elseif (! array_is_list($v) && $depth < 3) {
                array_push($blocks, ...$this->flatten($v, $lbl[0], $depth + 1));
            }
        }

        return $blocks;
    }

    private function kind(string $key, mixed $v): string
    {
        if (is_string($v) && preg_match('/^\d{4}-\d\d-\d\d/', $v) && (str_contains($key, 'date') || str_ends_with($key, '_on') || in_array($key, ['from', 'to'], true))) {
            return 'date';
        }
        if (in_array($key, self::NOT_MONEY, true) || str_ends_with($key, '_id') || ! (is_int($v) || (is_string($v) && $v !== '' && ctype_digit(ltrim($v, '-'))))) {
            return 'text';
        }

        return 'money';
    }

    private function label(string $key, string $lang): string
    {
        static $map = null;
        $map ??= [
            'account' => ['Compte', 'Account'], 'name' => ['Intitulé', 'Name'], 'debit' => ['Débit', 'Debit'], 'credit' => ['Crédit', 'Credit'], 'balance' => ['Solde', 'Balance'], 'date' => ['Date', 'Date'],
            'number' => ['N°', 'No.'], 'description' => ['Libellé', 'Description'], 'amount' => ['Montant', 'Amount'], 'total' => ['Total', 'Total'], 'total_debit' => ['Total débit', 'Total debit'],
            'total_credit' => ['Total crédit', 'Total credit'], 'rows' => ['Lignes', 'Rows'], 'lines' => ['Lignes', 'Lines'], 'opening' => ['Solde d\'ouverture', 'Opening balance'], 'closing' => ['Solde de clôture', 'Closing balance'],
            'from' => ['Du', 'From'], 'to' => ['Au', 'To'], 'revenue' => ['Produits', 'Revenue'], 'expenses' => ['Charges', 'Expenses'], 'assets' => ['Actif', 'Assets'],
            'liabilities_and_equity' => ['Passif et capitaux propres', 'Liabilities and equity'], 'result' => ['Résultat', 'Result'], 'net_result' => ['Résultat net', 'Net result'],
            'vat_collected' => ['TVA collectée', 'VAT collected'], 'vat_deductible' => ['TVA déductible', 'VAT deductible'], 'vat_payable' => ['TVA à payer', 'VAT payable'],
            'tourist_tax_collected' => ['Taxe de séjour collectée', 'Tourist tax collected'], 'bill_to_name' => ['Client', 'Customer'], 'issue_date' => ['Émission', 'Issued'], 'due_date' => ['Échéance', 'Due'],
            'paid' => ['Réglé', 'Paid'], 'outstanding' => ['Reste dû', 'Outstanding'], 'bucket' => ['Tranche', 'Bucket'], 'journal_code' => ['Journal', 'Journal'], 'current' => ['Non échu', 'Not due'],
            'status' => ['Statut', 'Status'], 'code' => ['Code', 'Code'], 'type' => ['Type', 'Type'], 'department' => ['Département', 'Department'], 'budget_ytd' => ['Budget cumulé', 'Budget YTD'],
            'actual_ytd' => ['Réalisé cumulé', 'Actual YTD'], 'budget_full' => ['Budget annuel', 'Budget full year'], 'actual_full' => ['Réalisé annuel', 'Actual full year'], 'forecast' => ['Prévision', 'Forecast'],
            'variance' => ['Écart', 'Variance'], 'reference' => ['Référence', 'Reference'], 'currency' => ['Devise', 'Currency'], 'kind' => ['Nature', 'Kind'], 'year' => ['Exercice', 'Year'],
        ];

        return ($map[$key][$lang === 'en' ? 1 : 0] ?? null) ?: ucfirst(str_replace('_', ' ', $key));
    }

    private function wrap(string $kind, array $title, array $meta, array $blocks, bool $landscape = false, array $extra = []): array
    {
        $code = strtoupper(substr(preg_replace('/[^a-z]/i', '', $kind), 0, 3));

        return ['guest_id' => null, 'landscape' => $landscape, 'opts' => ['number' => $code.'-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(1))), 'source_type' => str_replace('-', '_', $kind)],
            'sheet' => ['title' => $title, 'meta' => $meta, 'blocks' => $blocks] + $extra];
    }

    public function report(string $kind, array $q): array
    {
        $y = (int) ($this->hotel()?->business_date?->year ?? now()->year);
        $from = $q['from'] ?? "$y-01-01";
        $to = $q['to'] ?? ($this->hotel()?->business_date?->toDateString() ?? now()->toDateString());
        $range = [['Période', 'Period', $this->date($from).' - '.$this->date($to)]];
        $defs = [
            'trial-balance' => ['Balance générale', 'Trial balance', 'accounting/reports/trial-balance', compact('from', 'to'), $range, false],
            'general-ledger' => ['Grand livre', 'General ledger', 'accounting/reports/general-ledger', ['account' => $q['account'] ?? null, 'from' => $from, 'to' => $to], array_merge([['Compte', 'Account', $q['account'] ?? '']], $range), true],
            'income-statement' => ['Compte de résultat', 'Income statement', 'accounting/reports/income-statement', compact('from', 'to'), $range, false],
            'balance-sheet' => ['Bilan', 'Balance sheet', 'accounting/reports/balance-sheet', ['date' => $to], [['Au', 'As at', $this->date($to)]], false],
            'vat-return' => ['Déclaration de TVA', 'VAT return', 'accounting/reports/vat', compact('from', 'to'), $range, false],
            'cash-flow' => ['Tableau des flux de trésorerie', 'Cash-flow statement', 'accounting/reports/cash-flow', compact('from', 'to'), $range, false],
            'ageing' => ['Balance âgée clients', 'Receivables ageing', 'accounting/reports/receivables-ageing', [], [['Au', 'As at', $this->date($this->hotel()?->business_date)]], true],
        ];
        [$fr, $en, $path, $params, $meta, $landscape] = $defs[$kind];
        $data = $this->api->get($path, array_filter($params, fn ($v) => $v !== null));

        return $this->wrap($kind, [$fr, $en], $meta, $this->flatten((array) $data), $landscape, ['sign' => [['Établi par', 'Prepared by'], ['Approuvé par', 'Approved by']]]);
    }

    public function bankReconciliation(int $bankId, array $q): array
    {
        $bank = BankAccount::findOrFail($bankId);
        $to = $q['to'] ?? ($this->hotel()?->business_date?->toDateString() ?? now()->toDateString());
        $from = $q['from'] ?? Carbon::parse($to)->startOfMonth()->toDateString();
        $d = $this->api->get("accounting/bank-accounts/$bankId/reconciliation", compact('from', 'to'));
        $summary = [
            ['Solde du relevé bancaire', 'Bank statement balance', $d['statement_closing_balance'], 'money'], ['Écritures comptables non rapprochées', 'Unmatched ledger entries', $d['unmatched_ledger_total'], 'money'], ['Solde relevé ajusté', 'Adjusted statement balance', $d['adjusted_statement_balance'], 'money'],
            ['Solde comptable', 'Book balance', $d['book_closing_balance'], 'money'], ['Lignes de relevé non rapprochées', 'Unmatched statement lines', $d['unmatched_statement_total'], 'money'], ['Solde comptable ajusté', 'Adjusted book balance', $d['adjusted_book_balance'], 'money'],
            ['Différence', 'Difference', $d['difference'], 'money'], ['Rapproché', 'Reconciled', $d['reconciled'], 'bool'],
        ];
        $blocks = [['type' => 'kv', 'title' => ['État de rapprochement', 'Reconciliation'], 'rows' => $summary],
            ['type' => 'table', 'title' => ['Lignes de relevé non rapprochées', 'Unmatched statement lines'], 'cols' => [self::col('date', 'Date', 'Date', 'date'), self::col('description', 'Libellé', 'Description'), self::col('reference', 'Référence', 'Reference'), self::col('amount', 'Montant', 'Amount', 'money')], 'rows' => $d['unmatched_statement_lines']],
            ['type' => 'table', 'title' => ['Écritures comptables non rapprochées', 'Unmatched ledger entries'], 'cols' => [self::col('date', 'Date', 'Date', 'date'), self::col('number', 'Pièce', 'Entry'), self::col('description', 'Libellé', 'Description'), self::col('amount', 'Montant', 'Amount', 'money')], 'rows' => $d['unmatched_ledger_lines']]];

        return $this->wrap('bank-reconciliation', ['État de rapprochement bancaire', 'Bank reconciliation statement'], [['Compte', 'Account', $bank->name.' ('.$bank->gl_account.')'], ['Période', 'Period', $this->date($from).' - '.$this->date($to)]], $blocks, false, ['sign' => [['Préparé par', 'Prepared by'], ['Approuvé par', 'Approved by']]]);
    }

    public function assetRegister(array $q, bool $schedule): array
    {
        $year = (int) ($q['year'] ?? $this->hotel()?->business_date?->year ?? now()->year);
        $rows = FixedAsset::orderBy('code')->get()->map(function ($a) use ($year) {
            $depr = max(0, (int) $a->cost - (int) $a->salvage_value);
            $monthly = $a->useful_life_months > 0 ? (int) round($depr / $a->useful_life_months) : 0;
            $start = Carbon::parse($a->acquired_on)->startOfMonth();
            $m0 = max(0, min((int) $a->useful_life_months, (int) $start->diffInMonths(Carbon::create($year, 1, 1), false)));
            $m1 = max(0, min((int) $a->useful_life_months, (int) $start->diffInMonths(Carbon::create($year + 1, 1, 1), false)));
            $charge = $a->status === 'disposed' ? 0 : min($depr - $monthly * $m0, $monthly * ($m1 - $m0));

            return ['code' => $a->code, 'name' => $a->name, 'acquired_on' => $a->acquired_on, 'cost' => (int) $a->cost, 'salvage' => (int) $a->salvage_value, 'life' => (int) $a->useful_life_months, 'monthly' => $monthly,
                'accumulated' => (int) $a->accumulated, 'nbv' => (int) $a->netBookValue(), 'year_charge' => max(0, (int) $charge), 'status' => $a->status];
        })->all();
        $tot = fn ($k) => array_sum(array_column($rows, $k));
        if ($schedule) {
            $cols = [self::col('code', 'Code', 'Code'), self::col('name', 'Immobilisation', 'Asset'), self::col('cost', 'Coût', 'Cost', 'money'), self::col('salvage', 'Valeur résiduelle', 'Salvage', 'money'), self::col('life', 'Durée (mois)', 'Life (months)', 'int'), self::col('monthly', 'Dotation mensuelle', 'Monthly charge', 'money'), self::col('year_charge', 'Dotation '.$year, 'Charge '.$year, 'money'), self::col('accumulated', 'Amortissements cumulés', 'Accumulated', 'money'), self::col('nbv', 'VNC', 'Net book value', 'money')];
            $title = ['Tableau d\'amortissement', 'Depreciation schedule'];
        } else {
            $cols = [self::col('code', 'Code', 'Code'), self::col('name', 'Immobilisation', 'Asset'), self::col('acquired_on', 'Acquise le', 'Acquired', 'date'), self::col('cost', 'Coût', 'Cost', 'money'), self::col('accumulated', 'Amortissements', 'Accumulated', 'money'), self::col('nbv', 'VNC', 'Net book value', 'money'), self::col('status', 'Statut', 'Status')];
            $title = ['Registre des immobilisations', 'Fixed-asset register'];
        }

        return $this->wrap($schedule ? 'depreciation-schedule' : 'asset-register', $title, [['Exercice', 'Year', (string) $year]],
            [['type' => 'table', 'cols' => $cols, 'rows' => $rows, 'totals' => [['Total coût', 'Total cost', $tot('cost')], ['Total VNC', 'Total net book value', $tot('nbv')]]]], true);
    }

    public function budgetVariance(int $id, array $q): array
    {
        $by = in_array($q['by'] ?? '', ['account', 'department', 'month'], true) ? $q['by'] : 'account';
        $d = (array) $this->api->get("accounting/core/budgets/$id/variance", array_filter(['by' => $by, 'through' => $q['through'] ?? null, 'method' => $q['method'] ?? null]));
        $rows = $d['rows'] ?? (array_is_list($d) ? $d : []);
        if ($rows && is_array($rows[0] ?? null)) {
            $keys = array_values(array_filter(array_keys($rows[0]), fn ($k) => is_scalar($rows[0][$k]) || $rows[0][$k] === null));
            $blocks = [['type' => 'table', 'cols' => array_map(fn ($c) => self::col($c, $this->label($c, 'fr'), $this->label($c, 'en'), $this->kind($c, $rows[0][$c] ?? null)), $keys), 'rows' => $rows]];
            $blocks = array_merge($this->flatten(array_filter($d, fn ($v) => is_scalar($v))), $blocks);
        } else {
            $blocks = $this->flatten($d);
        }

        return $this->wrap('budget-variance', ['Écart budgétaire', 'Budget variance report'], [['Regroupement', 'Grouped by', $by]], $blocks, true);
    }

    public function dailyReport(array $q): array
    {
        $hotel = $this->hotel();
        $date = $q['date'] ?? $hotel?->business_date?->toDateString() ?? now()->toDateString();
        $hid = $this->ctx->requireHotelId();
        $day = DB::table('business_days')->where('hotel_id', $hid)->where('date', $date)->first();
        $summary = $day && $day->summary ? (array) json_decode($day->summary, true) : [];
        $pay = FolioLine::where('hotel_id', $hid)->whereDate('business_date', $date)->whereIn('type', ['payment', 'refund'])->selectRaw('code, count(*) n, -sum(amount) total')->groupBy('code')->get()
            ->map(fn ($r) => ['method' => $this->methodName($r->code), 'n' => (int) $r->n, 'total' => (int) $r->total])->all();
        $dept = FolioLine::where('hotel_id', $hid)->whereDate('business_date', $date)->where('type', 'charge')->where('reversed', false)->selectRaw('department, sum(amount) total, sum(tax_amount) tax')->groupBy('department')->get()
            ->map(fn ($r) => ['department' => $r->department ?: '-', 'total' => (int) $r->total, 'tax' => (int) $r->tax])->all();
        $kpi = (array) $this->api->get('reports/dashboard');
        $pos = (array) $this->api->get('pos/reports/day', ['date' => $date]);
        $flatKpi = array_filter((array) ($kpi['today'] ?? $kpi), fn ($v) => is_scalar($v) || $v === null);
        $blocks = array_merge(
            $flatKpi ? [['type' => 'kv', 'title' => ['Indicateurs du jour', 'Daily KPIs'], 'rows' => array_map(fn ($k, $v) => [$this->label((string) $k, 'fr'), $this->label((string) $k, 'en'), $v, $this->kind((string) $k, $v)], array_keys($flatKpi), $flatKpi)]] : [],
            [['type' => 'table', 'title' => ['Chiffre d\'affaires par département', 'Revenue by department'], 'cols' => [self::col('department', 'Département', 'Department'), self::col('total', 'TTC', 'Gross', 'money'), self::col('tax', 'dont taxes', 'of which tax', 'money')], 'rows' => $dept, 'totals' => [['Total', 'Total', array_sum(array_column($dept, 'total'))]]],
                ['type' => 'table', 'title' => ['Encaissements folio', 'Folio takings'], 'cols' => [self::col('method', 'Mode', 'Method'), self::col('n', 'Nb', 'Count', 'int'), self::col('total', 'Montant', 'Amount', 'money')], 'rows' => $pay, 'totals' => [['Total', 'Total', array_sum(array_column($pay, 'total'))]]],
                ['type' => 'kv', 'title' => ['Points de vente', 'Outlets'], 'rows' => [['Tickets', 'Checks', (int) ($pos['checks'] ?? 0), 'int'], ['Couverts', 'Covers', (int) ($pos['covers'] ?? 0), 'int'], ['Brut', 'Gross', (int) ($pos['gross'] ?? 0), 'money'], ['Net', 'Net', (int) ($pos['net'] ?? 0), 'money']]]],
            $summary ? $this->flatten($summary, 'Audit de nuit / Night audit') : [],
        );

        return $this->wrap('daily-report', ['Rapport journalier du directeur / audit de nuit', 'Manager\'s daily report / night audit report'],
            [['Journée comptable', 'Business date', $this->date($date)], ['Statut de la journée', 'Day status', $day->status ?? 'open'], ['Clôturée le', 'Closed', $this->dt($day->closed_at ?? null)], ['Clôturée par', 'Closed by', $this->user($day->closed_by ?? null)]], $blocks, false,
            ['sign' => [['Auditeur de nuit', 'Night auditor'], ['Directeur', 'Manager']]]);
    }
}

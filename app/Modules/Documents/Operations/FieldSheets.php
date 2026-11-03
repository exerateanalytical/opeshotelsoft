<?php

namespace App\Modules\Documents\Operations;

use App\Modules\Documents\Application\InternalApi;
use App\Modules\FrontOffice\Domain\GuestRegisterEntry;
use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Housekeeping\Domain\LostFoundItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;

/** D-C front office, housekeeping and POS documents: rows 15, 16, 17, 22, 24. */
class FieldSheets
{
    use SheetHelpers;

    public function __construct(private InternalApi $api) {}

    private function blockOf(int $id): RoomBlock
    {
        return RoomBlock::with('allocations')->findOrFail($id);
    }

    /** Row 15a: group contract (allotment, rates, cut-off, terms). */
    public function groupContract(int $id): array
    {
        $b = $this->blockOf($id);
        $types = RoomType::whereIn('id', $b->allocations->pluck('room_type_id')->unique())->get()->keyBy('id');
        $rows = [];
        $total = 0;
        foreach ($b->allocations->groupBy('room_type_id') as $typeId => $allocs) {
            $value = (int) $allocs->sum(fn ($a) => $a->rooms * (int) $a->rate);
            $total += $value;
            $rows[] = ['type' => $types[$typeId]->name ?? '#'.$typeId, 'rooms' => (int) $allocs->max('rooms'), 'nights' => $allocs->count(),
                'rate' => (int) ($allocs->first()->rate ?? 0), 'value' => $value];
        }
        $company = $b->company_id ? Company::find($b->company_id) : null;
        $sheet = [
            'title' => ['Contrat de groupe', 'Group contract'],
            'meta' => [
                ['Groupe / événement', 'Group / event', $b->name], ['Code', 'Code', $b->code], ['Client', 'Client', $company?->name],
                ['Arrivée', 'Arrival', $this->date($b->arrival)], ['Départ', 'Departure', $this->date($b->departure)],
                ['Date limite de libération (cut-off)', 'Release cut-off date', $this->date($b->cutoff_date)], ['Statut', 'Status', $b->status],
                ['Plan tarifaire', 'Rate plan', $b->rate_plan_id ? RatePlan::find($b->rate_plan_id)?->name : null],
            ],
            'blocks' => [
                ['type' => 'table', 'title' => ['Allotement de chambres', 'Room allotment'], 'cols' => [
                    self::col('type', 'Type de chambre', 'Room type'), self::col('rooms', 'Chambres / nuit', 'Rooms / night', 'int'), self::col('nights', 'Nuits', 'Nights', 'int'),
                    self::col('rate', 'Tarif négocié', 'Negotiated rate', 'money'), self::col('value', 'Valeur du bloc', 'Block value', 'money'),
                ], 'rows' => $rows, 'totals' => [['Valeur totale du bloc (hors taxes de séjour)', 'Total block value (excluding city taxes)', $total]]],
                ['type' => 'text', 'title' => ['Conditions', 'Terms'], 'text' => [
                    "1. Les chambres non attribuées à la date limite de libération retournent à la vente générale.\n2. La liste des participants (rooming list) doit être transmise avant la date limite.\n3. Un acompte peut être exigé selon les conditions de l'hôtel ; le solde est réglé au départ ou sur facture pour le compte société.\n4. Toute annulation ou réduction du bloc après la date limite peut donner lieu à des frais.",
                    "1. Rooms not allocated by the release cut-off date return to general sale.\n2. The rooming list must be sent before the cut-off date.\n3. A deposit may be required under the hotel's conditions; the balance is settled at departure or invoiced to the company.\n4. Any cancellation or reduction of the block after the cut-off date may incur charges.",
                ]],
            ],
            'note' => $b->notes ? [$b->notes, $b->notes] : null,
            'sign' => [['Pour l\'hôtel (nom, cachet, signature)', 'For the hotel (name, stamp, signature)'], ['Pour le client (« lu et approuvé »)', 'For the client ("read and approved")']],
        ];
        $sheet = array_filter($sheet, fn ($v) => $v !== null);

        return $this->result('group_contract', $sheet, 'GC-'.$b->code, $b->id, ['amount' => $total, 'currency' => 'XAF']);
    }

    /** Row 15b: rooming list of a block (A4 landscape). */
    public function roomingList(int $id): array
    {
        $b = $this->blockOf($id);
        $res = Reservation::where('room_block_id', $b->id)->orderBy('id')->get();
        $guests = Guest::whereIn('id', $res->pluck('guest_id'))->get()->keyBy('id');
        $types = RoomType::whereIn('id', $res->pluck('room_type_id'))->get()->keyBy('id');
        $rooms = Room::whereIn('id', $res->pluck('room_id')->filter())->get()->keyBy('id');
        $rows = [];
        foreach ($res as $i => $r) {
            $g = $guests[$r->guest_id] ?? null;
            $rows[] = ['n' => $i + 1, 'guest' => $g ? trim($g->first_name.' '.$g->last_name) : '', 'nationality' => $g?->nationality, 'type' => $types[$r->room_type_id]->name ?? '',
                'room' => $rooms[$r->room_id]->number ?? '', 'arrival' => $this->date($r->arrival), 'departure' => $this->date($r->departure), 'adults' => (int) $r->adults,
                'number' => $r->number, 'status' => $r->status];
        }
        $allotted = (int) $b->allocations->groupBy('room_type_id')->sum(fn ($a) => $a->max('rooms'));
        $sheet = [
            'title' => ['Liste de répartition (rooming list)', 'Rooming list'],
            'meta' => [
                ['Groupe / événement', 'Group / event', $b->name.' ('.$b->code.')'], ['Client', 'Client', $b->company_id ? Company::find($b->company_id)?->name : null],
                ['Séjour', 'Stay', $this->date($b->arrival).' - '.$this->date($b->departure)], ['Chambres allouées', 'Rooms allotted', (string) $allotted],
                ['Réservations nominatives', 'Named reservations', (string) $res->count()],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('n', '#', '#', 'int'), self::col('guest', 'Client', 'Guest'), self::col('nationality', 'Nationalité', 'Nationality'), self::col('type', 'Type', 'Type'),
                self::col('room', 'Chambre', 'Room'), self::col('arrival', 'Arrivée', 'Arrival'), self::col('departure', 'Départ', 'Departure'), self::col('adults', 'Adultes', 'Adults', 'int'),
                self::col('number', 'Réservation', 'Reservation'), self::col('status', 'Statut', 'Status'),
            ], 'rows' => $rows]],
        ];

        return $this->result('rooming_list', $sheet, 'RL-'.$b->code, $b->id, ['size' => 'a4-landscape']);
    }

    /** Row 16: police register extract for a date range (A4 landscape). Personal data: no amount, no public detail. */
    public function policeRegister(?string $from, ?string $to): array
    {
        if (! $from || ! $to || ! strtotime($from) || ! strtotime($to) || $from > $to) {
            throw new DomainException('Give a valid from and to date.', 'invalid-range');
        }
        $rows = GuestRegisterEntry::where('arrival', '<=', $to)->where('departure', '>=', $from)->orderBy('arrival')->orderBy('id')->get()
            ->map(fn ($e) => ['room' => $e->room_number, 'name' => $e->full_name, 'nat' => $e->nationality, 'born' => $this->date($e->date_of_birth).' '.$e->place_of_birth,
                'job' => $e->profession, 'id' => trim($e->id_type.' '.$e->id_number), 'from' => $e->coming_from, 'to' => $e->going_to, 'arrival' => $this->date($e->arrival), 'departure' => $this->date($e->departure)])->all();
        $sheet = [
            'title' => ['Extrait du registre de police (fiche de police)', 'Police register extract'],
            'meta' => [['Période', 'Period', $this->date($from).' - '.$this->date($to)], ['Nombre d\'enregistrements', 'Entries', (string) count($rows)]],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('room', 'Ch.', 'Room'), self::col('name', 'Nom complet', 'Full name'), self::col('nat', 'Nationalité', 'Nationality'), self::col('born', 'Naissance', 'Born'),
                self::col('job', 'Profession', 'Occupation'), self::col('id', 'Pièce d\'identité', 'ID document'), self::col('from', 'Venant de', 'From'), self::col('to', 'Allant à', 'To'),
                self::col('arrival', 'Arrivée', 'Arrival'), self::col('departure', 'Départ', 'Departure'),
            ], 'rows' => $rows]],
            'note' => ['Extrait certifié conforme au registre tenu par l\'établissement. Document confidentiel destiné aux autorités.', 'Extract certified true to the register kept by the establishment. Confidential, for the authorities.'],
            'sign' => [['Le responsable de l\'établissement (cachet et signature)', 'Establishment manager (stamp and signature)']],
        ];

        return $this->result('police_register', $sheet, 'PR-'.str_replace('-', '', $from).'-'.str_replace('-', '', $to), null, ['size' => 'a4-landscape']);
    }

    /** Row 17: lost and found receipt. $stage is "handin" (item received) or "return" (item handed back to its owner). */
    public function lostFound(int $id, string $stage): array
    {
        $i = LostFoundItem::findOrFail($id);
        if ($stage === 'return' && $i->status !== 'returned') {
            throw new DomainException('This item has not been returned yet.', 'lost-found-not-returned');
        }
        $guest = $i->guest_id ? Guest::find($i->guest_id) : null;
        $room = $i->room_id ? Room::find($i->room_id) : null;
        $meta = [
            ['Objet', 'Item', $i->description], ['Lieu de découverte', 'Found at', $i->location], ['Chambre', 'Room', $room?->number],
            ['Trouvé le', 'Found on', $this->date($i->found_on)], ['Trouvé par', 'Found by', $this->userName($i->found_by)], ['Client concerné', 'Guest concerned', $guest ? trim($guest->first_name.' '.$guest->last_name) : null],
        ];
        if ($stage === 'return') {
            array_push($meta, ['Remis à', 'Handed to', $i->returned_to], ['Remis le', 'Handed on', $this->dt($i->returned_at)]);
        } else {
            $meta[] = ['Statut', 'Status', $i->status];
        }
        $sheet = [
            'title' => $stage === 'return' ? ['Reçu de restitution d\'objet trouvé', 'Lost and found return receipt'] : ['Reçu de dépôt d\'objet trouvé', 'Lost and found hand-in receipt'],
            'meta' => $meta,
            'note' => $stage === 'return'
                ? ['Je reconnais avoir récupéré l\'objet décrit ci-dessus en bon état et dégage l\'établissement de toute responsabilité.', 'I acknowledge having collected the item described above in good condition and release the establishment from any liability.']
                : ['L\'objet décrit ci-dessus a été remis au service des objets trouvés. Il sera conservé et restitué sur justificatif.', 'The item described above has been handed in to the lost and found office. It will be kept and returned on proof of ownership.'],
            'sign' => $stage === 'return' ? [['Signature du bénéficiaire (pièce d\'identité vérifiée)', 'Recipient signature (ID checked)'], ['Agent de l\'établissement', 'Staff member']]
                : [['Personne ayant trouvé l\'objet', 'Finder'], ['Agent des objets trouvés', 'Lost and found officer']],
        ];

        return $this->result($stage === 'return' ? 'lost_found_return' : 'lost_found_handin', $sheet, ($stage === 'return' ? 'OTR-' : 'OTD-').str_pad((string) $i->id, 5, '0', STR_PAD_LEFT), $i->id);
    }

    /** Row 22: kitchen / bar ticket data (80 mm). Loaded through the POS endpoint so its permission and tenant checks apply. */
    public function kot(int $checkId, ?string $firedAt): array
    {
        $d = $this->api->get('pos/checks/'.$checkId.'/tickets', $firedAt ? ['fired_at' => $firedAt] : []);
        $number = 'KOT-'.$d['check']['number'].($firedAt ? '-'.Carbon::parse($firedAt)->format('His') : '');

        return [
            'type' => 'kot', 'view' => 'documents::operations.ticket80', 'size' => '80mm', 'number' => $number, 'source_type' => 'kot', 'source_id' => null,
            'title' => ['Ticket cuisine / bar', 'Kitchen / bar ticket'], 'data' => ['check' => $d['check'], 'server' => $d['server'], 'tickets' => $d['tickets']],
        ];
    }

    /** Row 24: POS day report print layout. */
    public function posDayReport(array $q): array
    {
        $date = $q['date'] ?? ($this->hotel()?->business_date?->toDateString() ?? now()->toDateString());
        $query = ['date' => $date] + (isset($q['outlet_id']) ? ['outlet_id' => (int) $q['outlet_id']] : []);
        $d = $this->api->get('pos/reports/day', $query);
        $outlet = isset($q['outlet_id']) ? Outlet::find((int) $q['outlet_id'])?->name : null;
        $covers = max(1, (int) $d['covers']);
        $settle = collect($d['by_settlement'] ?? [])->map(fn ($v, $k) => ['k' => $k, 'v' => (int) $v])->values()->all();
        $pay = collect($d['payments'] ?? [])->map(fn ($v, $k) => ['k' => $k, 'v' => (int) $v])->values()->all();
        $sheet = [
            'title' => ['Rapport journalier du point de vente', 'POS day report'],
            'meta' => [['Date', 'Date', $this->date($date)], ['Point de vente', 'Outlet', $outlet ?: 'All outlets / Tous']],
            'blocks' => [
                ['type' => 'kv', 'title' => ['Synthèse', 'Summary'], 'rows' => [
                    ['Notes réglées', 'Settled checks', (int) $d['checks'], 'int'], ['Couverts', 'Covers', (int) $d['covers'], 'int'],
                    ['Ventes brutes', 'Gross sales', (int) $d['gross'], 'money'], ['Remises', 'Discounts', (int) $d['discounts'], 'money'],
                    ['Ventes nettes', 'Net sales', (int) $d['net'], 'money'], ['Ticket moyen par couvert', 'Average per cover', (int) round($d['net'] / $covers), 'money'],
                    ['Lignes annulées', 'Voided lines', (int) $d['voids'], 'int'],
                ]],
                ['type' => 'table', 'title' => ['Par mode de règlement', 'By settlement'], 'cols' => [self::col('k', 'Mode', 'Method'), self::col('v', 'Montant', 'Amount', 'money')], 'rows' => $settle],
                ['type' => 'table', 'title' => ['Encaissements par moyen de paiement', 'Payments by method'], 'cols' => [self::col('k', 'Moyen', 'Method'), self::col('v', 'Montant', 'Amount', 'money')], 'rows' => $pay],
            ],
            'sign' => [['Responsable du point de vente', 'Outlet manager'], ['Caissier', 'Cashier']],
        ];

        return $this->result('pos_day_report', $sheet, 'PDR-'.str_replace('-', '', $date).(isset($q['outlet_id']) ? '-O'.(int) $q['outlet_id'] : ''), null, ['amount' => (int) $d['net'], 'currency' => 'XAF']);
    }
}

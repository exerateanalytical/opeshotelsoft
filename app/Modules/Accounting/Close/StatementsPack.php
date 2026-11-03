<?php

namespace App\Modules\Accounting\Close;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Accounting\Support\Sheets;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements pack in the SYSCOHADA révisé layout: bilan (actif brut / amortissements / net, passif), compte de résultat with
 * its intermediate balances, TAFIRE (from the cash-flow statement) and a notes annexes skeleton. Structured JSON for the document engine
 * and Excel here; PDF layouts belong to the document engine.
 *
 * GATE: the line refs and account-prefix mapping are a best-effort reading of the SYSCOHADA révisé tables. The chartered accountant
 * must validate them against the OHADA model (and the country's DSF) before the pack is filed or shown to third parties.
 */
class StatementsPack
{
    use Scoped;

    /** Class 2 / 3 / 5 debit and class 4 debit placements: account prefix => [ref, label]. Longest prefix wins. */
    public const ACTIF = [
        '20' => ['AD', 'Immobilisations incorporelles'], '21' => ['AD', 'Immobilisations incorporelles'], '22' => ['AJ', 'Terrains'], '23' => ['AK', 'Bâtiments'],
        '24' => ['AM', 'Matériel, mobilier et actifs biologiques'], '245' => ['AN', 'Matériel de transport'], '25' => ['AP', 'Avances et acomptes versés sur immobilisations'],
        '26' => ['AR', 'Titres de participation'], '27' => ['AS', 'Autres immobilisations financières'], '2' => ['AM', 'Matériel, mobilier et actifs biologiques'],
        '3' => ['BB', 'Stocks et encours'], '409' => ['BH', 'Fournisseurs, avances versées'], '41' => ['BI', 'Clients'], '48' => ['BA', 'Actif circulant HAO'], '478' => ['BU', 'Écart de conversion - actif'],
        '42' => ['BJ', 'Autres créances'], '43' => ['BJ', 'Autres créances'], '44' => ['BJ', 'Autres créances'], '45' => ['BJ', 'Autres créances'], '46' => ['BJ', 'Autres créances'], '47' => ['BJ', 'Autres créances'], '4' => ['BJ', 'Autres créances'],
        '50' => ['BQ', 'Titres de placement'], '51' => ['BR', 'Valeurs à encaisser'], '5' => ['BS', 'Banques, chèques postaux, caisse'],
    ];

    /** Credit-side placements (class 1 and class 4 / 5 credit balances). */
    public const PASSIF = [
        '101' => ['CA', 'Capital'], '102' => ['CA', 'Capital'], '103' => ['CA', 'Capital'], '104' => ['CA', 'Capital'], '105' => ['CB', 'Primes liées au capital social'], '109' => ['CA', 'Capital'],
        '11' => ['CD', 'Réserves'], '12' => ['CF', 'Report à nouveau'], '13' => ['CJ', 'Résultat net de l\'exercice'], '14' => ['CL', 'Subventions d\'investissement'], '15' => ['CM', 'Provisions réglementées'],
        '16' => ['DA', 'Emprunts et dettes financières diverses'], '17' => ['DB', 'Dettes de location-acquisition'], '18' => ['DA', 'Emprunts et dettes financières diverses'], '19' => ['DC', 'Provisions financières pour risques et charges'],
        '1' => ['CD', 'Réserves'],
        '40' => ['DJ', 'Fournisseurs d\'exploitation'], '419' => ['DI', 'Clients, avances reçues'], '42' => ['DL', 'Dettes sociales'], '43' => ['DL', 'Dettes sociales'], '44' => ['DK', 'Dettes fiscales'],
        '45' => ['DM', 'Autres dettes'], '46' => ['DM', 'Autres dettes'], '47' => ['DM', 'Autres dettes'], '479' => ['DV', 'Écart de conversion - passif'], '48' => ['DH', 'Dettes circulantes HAO'], '49' => ['DN', 'Provisions pour risques à court terme'],
        '4' => ['DM', 'Autres dettes'], '5' => ['DR', 'Banques, découverts'],
    ];

    /** Compte de résultat: ref => [label, side (P produit / C charge), prefixes]. */
    public const RESULT = [
        'TA' => ['Ventes de marchandises', 'P', ['701']], 'RA' => ['Achats de marchandises', 'C', ['601']], 'RB' => ['Variation de stocks de marchandises', 'C', ['6031']],
        'TB' => ['Ventes de produits fabriqués', 'P', ['702', '703', '704']], 'TC' => ['Travaux, services vendus (hébergement, restauration, annexes)', 'P', ['70', '705', '706']], 'TD' => ['Produits accessoires', 'P', ['707', '708', '709']],
        'TE' => ['Production stockée', 'P', ['73']], 'TF' => ['Production immobilisée', 'P', ['72']], 'TG' => ['Subventions d\'exploitation', 'P', ['71']], 'TH' => ['Autres produits', 'P', ['75']],
        'TI' => ['Transferts de charges d\'exploitation', 'P', ['78', '781']], 'TJ' => ['Reprises de provisions d\'exploitation', 'P', ['791', '798']],
        'RC' => ['Achats de matières premières et fournitures liées', 'C', ['602']], 'RD' => ['Variation de stocks de matières premières', 'C', ['6032']],
        'RE' => ['Autres achats', 'C', ['60', '604', '605', '608', '609']], 'RF' => ['Variation de stocks d\'autres approvisionnements', 'C', ['6033']],
        'RG' => ['Transports', 'C', ['61']], 'RH' => ['Services extérieurs', 'C', ['62', '63']], 'RI' => ['Impôts et taxes', 'C', ['64']], 'RJ' => ['Autres charges', 'C', ['65']],
        'RK' => ['Charges de personnel', 'C', ['66']], 'RL' => ['Dotations aux amortissements, provisions et dépréciations', 'C', ['68', '69']],
        'TK' => ['Revenus financiers et assimilés', 'P', ['77']], 'TL' => ['Reprises de provisions financières', 'P', ['787', '797']],
        'RM' => ['Frais financiers et charges assimilées', 'C', ['67']], 'RN' => ['Dotations financières', 'C', ['687', '697']],
        'TN' => ['Produits des cessions d\'immobilisations', 'P', ['82']], 'TO' => ['Autres produits HAO', 'P', ['84', '86', '88']],
        'RO' => ['Valeurs comptables des cessions d\'immobilisations', 'C', ['81']], 'RP' => ['Autres charges HAO', 'C', ['83', '85']],
        'RQ' => ['Participation des travailleurs', 'C', ['87']], 'RS' => ['Impôts sur le résultat', 'C', ['89']],
    ];

    public function __construct(TenantContext $ctx, private Ledger $ledger, private FinancialStatements $flows, private AuditTrail $audit)
    {
        $this->ctx = $ctx;
    }

    public function build(string $from, string $to): array
    {
        if ($to < $from) {
            throw new DomainException('The period end is before its start.', 'bad-range');
        }
        $names = DB::table('accounts')->where('hotel_id', $this->hotelId())->pluck('name', 'code');
        $cur = $this->placements($from, $to);
        $prevFrom = Carbon::parse($from)->subYear()->toDateString();
        $prevTo = Carbon::parse($to)->subYear()->toDateString();
        $prev = $this->placements($prevFrom, $prevTo);

        $actif = $passif = [];
        foreach ($cur['actif'] as $ref => $l) {
            $actif[] = $l + ['ref' => $ref, 'net_prev' => $prev['actif'][$ref]['net'] ?? 0];
        }
        foreach ($cur['passif'] as $ref => $l) {
            $passif[] = $l + ['ref' => $ref, 'amount_prev' => $prev['passif'][$ref]['amount'] ?? 0];
        }
        usort($actif, fn ($a, $b) => $a['ref'] <=> $b['ref']);
        usort($passif, fn ($a, $b) => $a['ref'] <=> $b['ref']);
        $totalActif = array_sum(array_column($actif, 'net'));
        $totalPassif = array_sum(array_column($passif, 'amount'));

        $res = $this->result($from, $to);
        $resPrev = $this->result($prevFrom, $prevTo);
        $flow = $this->flows->cashFlow($from, $to);

        $hotel = DB::table('hotels')->find($this->hotelId());

        return [
            'meta' => ['layout' => 'SYSCOHADA révisé', 'hotel' => $hotel->name ?? '', 'country' => $hotel->country ?? null, 'tax_id' => $hotel->tax_id ?? null, 'currency' => 'XAF',
                'period_from' => $from, 'period_to' => $to, 'comparative_from' => $prevFrom, 'comparative_to' => $prevTo, 'generated_at' => now()->toIso8601String(),
                'gate' => 'Mapping to be validated by the chartered accountant before filing.'],
            'bilan' => ['actif' => $actif, 'passif' => $passif, 'total_actif' => $totalActif, 'total_passif' => $totalPassif, 'balanced' => $totalActif === $totalPassif,
                'unmapped' => $cur['unmapped'], 'prior_years_unclosed_result' => $cur['prior_unclosed']],
            'resultat' => ['lines' => $res['lines'], 'balances' => $res['balances'], 'net_result' => $res['net'], 'net_result_prev' => $resPrev['net'], 'check' => $res['net'] === $cur['result'], 'comparative' => $resPrev['balances']],
            'tafire' => $this->tafire($flow),
            'notes' => $this->notes($from, $to, $names),
        ];
    }

    public function sheets(array $p): array
    {
        $m = $p['meta'];
        $head = [[$m['hotel'], $m['layout']], ['period', $m['period_from'].' / '.$m['period_to']]];
        $actif = array_merge($head, [['ref', 'label', 'brut', 'amort', 'net', 'net N-1']]);
        foreach ($p['bilan']['actif'] as $l) {
            $actif[] = [$l['ref'], $l['label'], $l['brut'], $l['amort'], $l['net'], $l['net_prev']];
        }
        $actif[] = ['', 'TOTAL ACTIF', '', '', $p['bilan']['total_actif'], ''];
        $passif = array_merge($head, [['ref', 'label', 'montant', 'montant N-1']]);
        foreach ($p['bilan']['passif'] as $l) {
            $passif[] = [$l['ref'], $l['label'], $l['amount'], $l['amount_prev']];
        }
        $passif[] = ['', 'TOTAL PASSIF', $p['bilan']['total_passif'], ''];
        $res = array_merge($head, [['ref', 'label', 'montant']]);
        foreach ($p['resultat']['lines'] as $l) {
            $res[] = [$l['ref'], $l['label'], $l['amount']];
        }
        foreach ($p['resultat']['balances'] as $b) {
            $res[] = [$b['ref'], $b['label'], $b['amount']];
        }
        $taf = array_merge($head, [['label', 'montant']]);
        foreach ($p['tafire']['lines'] as $l) {
            $taf[] = [$l['label'], $l['amount']];
        }
        $notes = array_merge($head, [['note', 'title', 'status', 'detail']]);
        foreach ($p['notes'] as $n) {
            $notes[] = [$n['note'], $n['title'], $n['status'], $n['rows'] ? json_encode($n['rows'], JSON_UNESCAPED_UNICODE) : $n['prompt']];
        }

        return ['Bilan - Actif' => $actif, 'Bilan - Passif' => $passif, 'Compte de résultat' => $res, 'TAFIRE' => $taf, 'Notes annexes' => $notes];
    }

    public function excel(array $p): string
    {
        return Sheets::xlsx($this->sheets($p));
    }

    public function save(string $from, string $to): array
    {
        $payload = $this->build($from, $to);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $id = DB::table('statement_packs')->insertGetId($this->tenant() + ['period_from' => $from, 'period_to' => $to, 'payload' => $json, 'hash' => hash('sha256', $json), 'generated_by' => $this->actorId(), ...$this->stamp()]);
        $this->audit->record('statement_pack_generated', 'statement_pack', $id, ['from' => $from, 'to' => $to, 'balanced' => $payload['bilan']['balanced']]);

        return $this->show($id);
    }

    public function packs(): array
    {
        return $this->table('statement_packs')->orderByDesc('id')->limit(100)->get(['id', 'period_from', 'period_to', 'status', 'hash', 'generated_by', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }

    public function show(int $id): array
    {
        $r = $this->table('statement_packs')->where('id', $id)->first() ?? throw new DomainException('Pack not found.', 'not-found');

        return ['id' => $r->id, 'status' => $r->status, 'hash' => $r->hash, 'payload' => json_decode($r->payload, true)];
    }

    public function finalize(int $id): array
    {
        $p = $this->show($id);
        if ($p['status'] === 'final') {
            throw new DomainException('The pack is already final.', 'already-final');
        }
        if (! $p['payload']['bilan']['balanced'] || ! $p['payload']['resultat']['check']) {
            throw new DomainException('The pack does not balance; fix the mapping or the ledger first.', 'unbalanced');
        }
        $this->table('statement_packs')->where('id', $id)->update(['status' => 'final', 'updated_at' => now()]);
        $this->audit->record('statement_pack_final', 'statement_pack', $id, ['hash' => $p['hash']]);

        return $this->show($id);
    }

    // ---- internals ----

    /** @return array{actif: array, passif: array, unmapped: array, result: int} */
    private function placements(string $from, string $to): array
    {
        $closing = $this->ledger->balances(null, $to);
        $period = $this->ledger->balances($from, $to);
        $actif = $passif = $unmapped = [];
        $result = 0;
        $priorUnclosed = 0; // class 6-8 of earlier years that were never closed to 12/13: shown in report à nouveau so the bilan balances
        foreach ($closing as $code => $b) {
            $code = (string) $code;
            $class = (int) $code[0];
            if ($class >= 6) {
                $result -= $period[$code]['balance'] ?? 0;
                $priorUnclosed -= $b['balance'] - ($period[$code]['balance'] ?? 0);

                continue;
            }
            $bal = $b['balance'];
            if ($bal === 0 || $class === 9) {
                continue;
            }
            $depr = ($class === 2 && in_array(substr($code, 0, 2), ['28', '29'], true)) || str_starts_with($code, '39') || str_starts_with($code, '491') || str_starts_with($code, '59');
            if ($depr) {
                $base = match (true) {
                    str_starts_with($code, '2845') => '245',
                    $class === 2 => '2'.$code[2],
                    str_starts_with($code, '39') => '3',
                    str_starts_with($code, '491') => '41',
                    default => '50',
                };
                [$ref, $label] = $this->lookup(self::ACTIF, $base);
                $actif[$ref] ??= ['label' => $label, 'brut' => 0, 'amort' => 0, 'net' => 0];
                $actif[$ref]['amort'] += $bal; // credit balance => negative
                continue;
            }
            $toActif = $class === 2 || $class === 3 || ($class >= 4 && $bal > 0);
            if ($class === 1) {
                $toActif = false;
            }
            if ($toActif) {
                [$ref, $label] = $this->lookup(self::ACTIF, $code);
                $actif[$ref] ??= ['label' => $label, 'brut' => 0, 'amort' => 0, 'net' => 0];
                $actif[$ref]['brut'] += $bal;
            } else {
                [$ref, $label] = $this->lookup(self::PASSIF, $code);
                $passif[$ref] ??= ['label' => $label, 'amount' => 0];
                $passif[$ref]['amount'] -= $bal;
            }
        }
        foreach ($actif as &$l) {
            $l['net'] = $l['brut'] + $l['amort'];
        }
        if ($result !== 0 || isset($passif['CJ'])) {
            $passif['CJ'] ??= ['label' => 'Résultat net de l\'exercice', 'amount' => 0];
            $passif['CJ']['amount'] += $result;
        }

        if ($priorUnclosed !== 0) {
            $passif['CF'] ??= ['label' => 'Report à nouveau', 'amount' => 0];
            $passif['CF']['amount'] += $priorUnclosed;
        }

        return ['actif' => $actif, 'passif' => $passif, 'unmapped' => $unmapped, 'result' => $result, 'prior_unclosed' => $priorUnclosed];
    }

    private function lookup(array $map, string $code): array
    {
        for ($len = strlen($code); $len >= 1; $len--) {
            if (isset($map[substr($code, 0, $len)])) {
                return $map[substr($code, 0, $len)];
            }
        }

        return ['ZZ', 'Non classé'];
    }

    private function result(string $from, string $to): array
    {
        $mv = $this->ledger->balances($from, $to);
        $amount = array_fill_keys(array_keys(self::RESULT), 0);
        foreach ($mv as $code => $b) {
            $code = (string) $code;
            if ($code[0] < '6' || $code[0] > '8') {
                continue;
            }
            $best = null;
            foreach (self::RESULT as $ref => [, , $prefixes]) {
                foreach ($prefixes as $p) {
                    if (str_starts_with($code, $p) && (! $best || strlen($p) > $best[1])) {
                        $best = [$ref, strlen($p)];
                    }
                }
            }
            if ($best) {
                $amount[$best[0]] += self::RESULT[$best[0]][1] === 'P' ? -$b['balance'] : $b['balance'];
            }
        }
        $a = fn (string ...$refs) => array_sum(array_map(fn ($r) => $amount[$r], $refs));
        $XA = $a('TA') - $a('RA', 'RB');
        $XB = $a('TA', 'TB', 'TC', 'TD');
        $XC = $XA + $a('TB', 'TC', 'TD', 'TE', 'TF', 'TG', 'TH', 'TI') - $a('RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RJ');
        $XD = $XC - $a('RI', 'RK');
        $XE = $XD + $a('TJ') - $a('RL');
        $XF = $a('TK', 'TL') - $a('RM', 'RN');
        $XG = $XE + $XF;
        $XH = $a('TN', 'TO') - $a('RO', 'RP');
        $XI = $XG + $XH - $a('RQ', 'RS');
        $lines = [];
        foreach (self::RESULT as $ref => [$label, $side]) {
            $lines[] = ['ref' => $ref, 'label' => $label, 'side' => $side, 'amount' => $amount[$ref]];
        }
        $bal = fn (string $ref, string $label, int $v) => ['ref' => $ref, 'label' => $label, 'amount' => $v];

        return ['lines' => $lines, 'net' => $XI, 'balances' => [
            $bal('XA', 'Marge commerciale', $XA), $bal('XB', 'Chiffre d\'affaires', $XB), $bal('XC', 'Valeur ajoutée', $XC), $bal('XD', 'Excédent brut d\'exploitation', $XD),
            $bal('XE', 'Résultat d\'exploitation', $XE), $bal('XF', 'Résultat financier', $XF), $bal('XG', 'Résultat des activités ordinaires', $XG), $bal('XH', 'Résultat hors activités ordinaires', $XH),
            $bal('XI', 'Résultat net', $XI),
        ]];
    }

    private function tafire(array $f): array
    {
        $s = collect($f['sections'])->keyBy('key');
        $op = collect($s['operating']['lines']);
        $line = fn (string $label, int $v, bool $sub = false) => ['label' => $label, 'amount' => $v, 'subtotal' => $sub];
        $bfr = (int) ($op[4]['amount'] + $op[5]['amount'] + $op[6]['amount']);

        return ['lines' => [
            $line('Capacité d\'autofinancement globale (CAFG)', (int) $op[3]['amount'], true),
            $line('Variation du BFR (stocks, créances, dettes)', $bfr),
            $line('Flux de trésorerie provenant des activités opérationnelles', (int) $s['operating']['total'], true),
            $line('Flux de trésorerie provenant des activités d\'investissement', (int) $s['investing']['total'], true),
            $line('Flux de trésorerie provenant des activités de financement', (int) $s['financing']['total'], true),
            $line('Variation de la trésorerie nette', (int) $f['net_change'], true),
            $line('Trésorerie d\'ouverture', (int) $f['opening_treasury']), $line('Trésorerie de clôture', (int) $f['closing_treasury']),
        ], 'check' => (bool) $f['check']];
    }

    private function notes(string $from, string $to, $names): array
    {
        $closing = $this->ledger->balances(null, $to);
        $opening = $this->ledger->balances(null, Carbon::parse($from)->subDay()->toDateString());
        $move = $this->ledger->balances($from, $to);
        $by = function (array $prefixes, ?array $source = null) use ($closing, $names) {
            $rows = [];
            foreach ($source ?? $closing as $code => $b) {
                foreach ($prefixes as $p) {
                    if (str_starts_with((string) $code, $p) && $b['balance'] !== 0) {
                        $rows[] = ['account' => (string) $code, 'name' => $names[(string) $code] ?? '', 'balance' => $b['balance']];
                        break;
                    }
                }
            }

            return $rows;
        };
        $fixed = [];
        foreach (['21', '22', '23', '24', '25', '26', '27'] as $p) {
            $o = array_sum(array_map(fn ($c, $b) => str_starts_with((string) $c, $p) ? $b['balance'] : 0, array_keys($opening), $opening));
            $d = array_sum(array_map(fn ($c, $b) => str_starts_with((string) $c, $p) ? $b['debit'] : 0, array_keys($move), $move));
            $c2 = array_sum(array_map(fn ($c, $b) => str_starts_with((string) $c, $p) ? $b['credit'] : 0, array_keys($move), $move));
            if ($o || $d || $c2) {
                $fixed[] = ['class' => $p, 'opening' => $o, 'acquisitions' => $d, 'disposals' => $c2, 'closing' => $o + $d - $c2];
            }
        }
        $auto = fn (string $n, string $t, array $rows) => ['note' => $n, 'title' => $t, 'status' => 'auto', 'rows' => $rows, 'prompt' => null];
        $manual = fn (string $n, string $t, string $prompt) => ['note' => $n, 'title' => $t, 'status' => 'to_complete', 'rows' => [], 'prompt' => $prompt];

        return [
            $manual('1', 'Dettes garanties par des sûretés réelles', 'List secured debts and the assets pledged.'),
            $manual('2', 'Informations obligatoires (méthodes comptables, évaluation)', 'Describe accounting principles, depreciation methods and any change of method.'),
            $auto('3A', 'Immobilisations (mouvements de l\'exercice)', $fixed),
            $manual('4', 'Immobilisations financières', 'Detail participations and other financial assets, with percentages held.'),
            $auto('6', 'Stocks', $by(['3'])), $auto('7', 'Clients', $by(['41'])),
            $auto('8', 'Autres créances', $by(['42', '43', '44', '45', '46', '47'])),
            $auto('11', 'Trésorerie', $by(['5'])), $auto('13', 'Capitaux propres', $by(['1'])),
            $auto('16', 'Dettes financières', $by(['16', '17'])),
            $auto('17', 'Fournisseurs et dettes d\'exploitation', $by(['40'])), $auto('18', 'Dettes fiscales et sociales', $by(['42', '43', '44'])),
            $manual('15', 'Engagements hors bilan', 'List guarantees given/received, leases and commitments.'),
            $auto('21', 'Chiffre d\'affaires', $by(['70'], $move)), $auto('22', 'Achats', $by(['60'], $move)),
            $auto('25', 'Charges de personnel', $by(['66'], $move)), $auto('27', 'Impôts et taxes', $by(['64'], $move)),
            $manual('30', 'Événements postérieurs à la clôture', 'Describe significant events after the balance sheet date.'),
            $manual('35', 'Effectifs, masse salariale et personnel extérieur', 'Headcount by category (can be taken from the HR module).'),
        ];
    }
}

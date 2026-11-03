<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use Illuminate\Support\Carbon;

/**
 * Cash-flow statement (indirect method, TAFIRE-style grouping) and the
 * DSF-ready export (trial balance mapped to DSF lines).
 *
 * GATE 3: the DSF line mapping below is a best-effort SYSCOHADA révisé
 * mapping by account prefix. It must be validated by the hotel's chartered
 * accountant against the current DSF template before any filing.
 */
class FinancialStatements
{
    /** account prefix (longest wins) => [DSF ref, label, statement]. */
    public const DSF_MAP = [
        '20' => ['AD', 'Immobilisations incorporelles', 'actif'],
        '21' => ['AD', 'Immobilisations incorporelles', 'actif'],
        '22' => ['AJ', 'Terrains', 'actif'],
        '23' => ['AK', 'Bâtiments', 'actif'],
        '24' => ['AM', 'Matériel, mobilier et actifs biologiques', 'actif'],
        '245' => ['AN', 'Matériel de transport', 'actif'],
        '25' => ['AP', 'Avances et acomptes versés sur immobilisations', 'actif'],
        '26' => ['AR', 'Titres de participation', 'actif'],
        '27' => ['AS', 'Autres immobilisations financières', 'actif'],
        '28' => ['AZ-', 'Amortissements (en déduction des immobilisations)', 'actif'],
        '3' => ['BB', 'Stocks et encours', 'actif'],
        '409' => ['BH', 'Fournisseurs, avances versées', 'actif'],
        '41' => ['BI', 'Clients', 'actif'],
        '42' => ['BJ', 'Autres créances', 'actif'],
        '44' => ['BJ', 'Autres créances', 'actif'],
        '47' => ['BJ', 'Autres créances', 'actif'],
        '48' => ['BJ', 'Autres créances', 'actif'],
        '50' => ['BQ', 'Titres de placement', 'actif'],
        '52' => ['BS', 'Banques, chèques postaux, caisse', 'actif'],
        '57' => ['BS', 'Banques, chèques postaux, caisse', 'actif'],
        '58' => ['BS', 'Banques, chèques postaux, caisse', 'actif'],
        '10' => ['CA', 'Capital', 'passif'],
        '11' => ['CD', 'Réserves', 'passif'],
        '12' => ['CH', 'Report à nouveau', 'passif'],
        '13' => ['CJ', 'Résultat net de l\'exercice', 'passif'],
        '16' => ['DA', 'Emprunts et dettes financières diverses', 'passif'],
        '40' => ['DJ', 'Fournisseurs d\'exploitation', 'passif'],
        '419' => ['DI', 'Clients, avances reçues', 'passif'],
        '43' => ['DL', 'Dettes sociales', 'passif'],
        '422' => ['DL', 'Dettes sociales', 'passif'],
        '481' => ['DM', 'Autres dettes', 'passif'],
        '60' => ['RA', 'Achats et variations de stocks', 'charges'],
        '603' => ['RB', 'Variation de stocks', 'charges'],
        '605' => ['RE', 'Autres achats', 'charges'],
        '61' => ['RG', 'Transports', 'charges'],
        '62' => ['RH', 'Services extérieurs', 'charges'],
        '63' => ['RH', 'Services extérieurs', 'charges'],
        '64' => ['RI', 'Impôts et taxes', 'charges'],
        '65' => ['RJ', 'Autres charges', 'charges'],
        '66' => ['RK', 'Charges de personnel', 'charges'],
        '67' => ['RM', 'Frais financiers et charges assimilées', 'charges'],
        '68' => ['RL', 'Dotations aux amortissements', 'charges'],
        '81' => ['RO', 'Valeurs comptables des cessions d\'immobilisations', 'charges'],
        '70' => ['TC', 'Travaux, services vendus', 'produits'],
        '701' => ['TA', 'Ventes de marchandises', 'produits'],
        '75' => ['TH', 'Autres produits', 'produits'],
        '77' => ['TK', 'Revenus financiers et assimilés', 'produits'],
        '82' => ['TN', 'Produits des cessions d\'immobilisations', 'produits'],
        '89' => ['RS', 'Impôts sur le résultat', 'charges'],
    ];

    public function __construct(private Ledger $ledger) {}

    /**
     * Indirect method. With Δx = movement (debit − credit) over the period:
     * CAFG = −Δ(6,7,8) + Δ68 + Δ81 + Δ82; ΔBFR = −Δ(3,4);
     * investing = −Δ2 − Δ68 − Δ81 − Δ82 (28 nets against 68; 81 against the class-2 credit);
     * financing = −Δ1. Their sum equals Δ5 (treasury).
     */
    public function cashFlow(string $from, string $to): array
    {
        $mv = $this->ledger->balances($from, $to);
        $sum = function (callable $f) use ($mv) {
            $t = 0;
            foreach ($mv as $code => $b) {
                if ($f((string) $code)) {
                    $t += $b['balance'];
                }
            }

            return $t;
        };
        $p = fn (string ...$prefixes) => fn ($c) => collect($prefixes)->contains(fn ($x) => str_starts_with($c, $x));

        $result = -$sum($p('6', '7', '8'));
        $dotations = $sum($p('68', '69')) - $sum($p('78', '79'));
        $disposals = $sum($p('81', '82')); // removes net gain (−) / loss (+) on disposal from result
        $cafg = $result + $dotations + $disposals;
        $stocks = -$sum($p('3'));
        $receivables = -$sum(fn ($c) => $c[0] === '4' && $this->isAssetSide($c));
        $payables = -$sum(fn ($c) => $c[0] === '4' && ! $this->isAssetSide($c));
        $operating = $cafg + $stocks + $receivables + $payables;

        $investing = -$sum($p('2')) - $sum($p('68', '69')) + $sum($p('78', '79')) - $sum($p('81', '82'));
        $proceeds = -$sum($p('82'));
        $acquisitions = $investing - $proceeds;
        $equity = -$sum($p('10', '11', '12', '13', '14', '15'));
        $debt = -$sum($p('16', '17', '18', '19'));
        $financing = $equity + $debt;

        $treasury = fn ($date) => array_sum(array_column(array_filter($this->ledger->balances(null, $date), fn ($k) => ((string) $k)[0] === '5', ARRAY_FILTER_USE_KEY), 'balance'));
        $opening = $treasury(Carbon::parse($from)->subDay()->toDateString());
        $closing = $treasury($to);

        return [
            'from' => $from, 'to' => $to,
            'sections' => [
                ['key' => 'operating', 'label' => __('srv.cashflow.operating'), 'total' => $operating, 'lines' => [
                    ['label' => __('srv.cashflow.net_result'), 'amount' => $result],
                    ['label' => __('srv.cashflow.depreciation'), 'amount' => $dotations],
                    ['label' => __('srv.cashflow.disposal_gains'), 'amount' => $disposals],
                    ['label' => __('srv.cashflow.cafg'), 'amount' => $cafg, 'subtotal' => true],
                    ['label' => __('srv.cashflow.stocks'), 'amount' => $stocks],
                    ['label' => __('srv.cashflow.receivables'), 'amount' => $receivables],
                    ['label' => __('srv.cashflow.payables'), 'amount' => $payables],
                ]],
                ['key' => 'investing', 'label' => __('srv.cashflow.investing'), 'total' => $investing, 'lines' => [
                    ['label' => __('srv.cashflow.acquisitions'), 'amount' => $acquisitions],
                    ['label' => __('srv.cashflow.proceeds'), 'amount' => $proceeds],
                ]],
                ['key' => 'financing', 'label' => __('srv.cashflow.financing'), 'total' => $financing, 'lines' => [
                    ['label' => __('srv.cashflow.equity'), 'amount' => $equity],
                    ['label' => __('srv.cashflow.debt'), 'amount' => $debt],
                ]],
            ],
            'net_change' => $operating + $investing + $financing,
            'opening_treasury' => $opening, 'closing_treasury' => $closing,
            'treasury_change' => $closing - $opening,
            'check' => ($operating + $investing + $financing) === ($closing - $opening),
        ];
    }

    /** Trial balance mapped to DSF lines. */
    public function dsf(string $from, string $to): array
    {
        $names = Account::pluck('name', 'code');
        $closing = $this->ledger->balances(null, $to);
        $period = $this->ledger->balances($from, $to);
        $lines = [];
        $unmapped = [];
        $accounts = [];
        foreach ($closing as $code => $b) {
            $code = (string) $code;
            $class = (int) $code[0];
            $amount = $class >= 6 ? ($period[$code]['balance'] ?? 0) : $b['balance'];
            if ($amount === 0) {
                continue;
            }
            $map = $this->mapAccount($code, $amount);
            if (! $map) {
                $unmapped[] = $code;

                continue;
            }
            [$ref, $label, $statement] = $map;
            $signed = in_array($statement, ['actif', 'charges'], true) ? $amount : -$amount;
            $lines[$ref] ??= ['ref' => $ref, 'label' => self::trans("srv.dsf.$ref", $label), 'statement' => $statement, 'amount' => 0, 'accounts' => []];
            $lines[$ref]['amount'] += $signed;
            $lines[$ref]['accounts'][] = $code;
            $accounts[] = ['account' => $code, 'name' => $names[$code] ?? '?', 'debit' => $b['debit'], 'credit' => $b['credit'], 'balance' => $amount, 'dsf_ref' => $ref];
        }
        ksort($lines);

        return [
            'from' => $from, 'to' => $to,
            'gate' => __('srv.dsf.gate'),
            'lines' => array_values($lines), 'accounts' => $accounts, 'unmapped_accounts' => $unmapped,
        ];
    }

    public function dsfCsv(array $dsf): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['dsf_ref', 'label', 'statement', 'amount', 'accounts'], ';', '"', '');
        foreach ($dsf['lines'] as $l) {
            fputcsv($out, [$l['ref'], $l['label'], $l['statement'], $l['amount'], implode(' ', $l['accounts'])], ';', '"', '');
        }
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    /** Translated label, or the fallback when the key is missing. */
    private static function trans(string $key, string $fallback): string
    {
        $t = __($key);

        return $t === $key ? $fallback : $t;
    }

    private function mapAccount(string $code, int $balance): ?array
    {
        // Partner accounts with a credit balance move to the liability side.
        if ($code[0] === '4' && $balance < 0 && $this->isAssetSide($code)) {
            return ['DM', 'Autres dettes', 'passif'];
        }
        for ($len = strlen($code); $len >= 1; $len--) {
            if (isset(self::DSF_MAP[substr($code, 0, $len)])) {
                return self::DSF_MAP[substr($code, 0, $len)];
            }
        }
        if ($code[0] === '4') {
            return $balance >= 0 ? ['BJ', 'Autres créances', 'actif'] : ['DM', 'Autres dettes', 'passif'];
        }

        return null;
    }

    /** Receivable-type class-4 accounts (clients, advances to staff, VAT deductible, suspense, disposal receivables). */
    private function isAssetSide(string $code): bool
    {
        if (str_starts_with($code, '419')) {
            return false;
        }

        return str_starts_with($code, '41') || str_starts_with($code, '409') || str_starts_with($code, '421') || str_starts_with($code, '445')
            || str_starts_with($code, '47') || str_starts_with($code, '485') || $code === C::role('suspense');
    }
}

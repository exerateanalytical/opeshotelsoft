<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Group consolidation (all hotels of the group, books in XAF): trial balance, income statement and balance
 * sheet after intercompany eliminations, optionally translated into a presentation currency.
 *
 * Eliminations
 *  - intra-group current accounts 451100/451200 are eliminated in full; any residual difference is shown
 *    as `ic_difference` (a reconciliation break between hotels) and parked in a technical row so the TB balances;
 *  - intercompany services: revenue and expense recorded in `intercompany_transactions` are removed pairwise.
 *
 * Translation (IAS 21 style, simplified): balance sheet and trial balance at the closing rate, income statement
 * at the average rate; the difference is the cumulative translation adjustment (cta) shown in equity.
 * Rates are "XAF per 1 unit of presentation currency".
 * Not handled (flagged for the accountant): unrealised intercompany profit in stock/fixed assets, minority
 * interests, equity-method investments, hyperinflation, goodwill.
 */
class Consolidation
{
    public const BASE = 'XAF';

    public function __construct(private TenantContext $ctx) {}

    public function build(string $from, string $to, string $currency = self::BASE, ?float $closing = null, ?float $average = null, ?array $hotelIds = null): array
    {
        $currency = strtoupper($currency);
        [$closing, $average] = $this->rates($currency, $to, $closing, $average);

        $hotels = Hotel::all()->when($hotelIds, fn ($c) => $c->whereIn('id', $hotelIds))->values();
        $ids = $hotels->pluck('id')->all();
        $names = DB::table('accounts')->whereIn('hotel_id', $ids)->orderBy('hotel_id')->pluck('name', 'code');

        // Cumulative balances to $to (trial balance, balance sheet); period balances (income statement).
        $cum = $this->balances($ids, null, $to);
        $per = $this->balances($ids, $from, $to);

        $perHotel = [];
        foreach ($hotels as $h) {
            $hb = $this->balances([$h->id], $from, $to);
            $rev = $exp = 0;
            foreach ($hb as $code => $b) {
                $code = (string) $code;
                $code[0] === '7' ? $rev += $b['credit'] - $b['debit'] : ($code[0] === '6' ? $exp += $b['debit'] - $b['credit'] : null);
            }
            $perHotel[] = ['hotel' => ['id' => $h->id, 'code' => $h->code, 'name' => $h->name], 'revenue' => $rev, 'expenses' => $exp, 'result' => $rev - $exp];
        }

        $elimCum = $this->eliminations($ids, null, $to, $cum);
        $elimPer = $this->eliminations($ids, $from, $to, $per);

        // ---- Trial balance (cumulative) ----
        $tbRows = [];
        $codes = array_map('strval', array_unique(array_merge(array_keys($cum), array_keys($elimCum['lines']))));
        sort($codes);
        foreach ($codes as $code) {
            $d = $cum[$code]['debit'] ?? 0;
            $c = $cum[$code]['credit'] ?? 0;
            $ed = $elimCum['lines'][$code]['debit'] ?? 0;
            $ec = $elimCum['lines'][$code]['credit'] ?? 0;
            $tbRows[] = ['account' => $code, 'name' => $names[$code] ?? ($code === 'IC-DIFF' ? 'Écart intragroupe à rapprocher' : '?'), 'debit' => $d, 'credit' => $c,
                'elim_debit' => $ed, 'elim_credit' => $ec, 'balance' => ($d - $c) + ($ed - $ec)];
        }
        $tbDebit = array_sum(array_map(fn ($r) => $r['debit'] + $r['elim_debit'], $tbRows));
        $tbCredit = array_sum(array_map(fn ($r) => $r['credit'] + $r['elim_credit'], $tbRows));

        // ---- Income statement (period, after eliminations) ----
        $rev = $exp = [];
        foreach (array_unique(array_merge(array_keys($per), array_keys($elimPer['lines']))) as $code) {
            $code = (string) $code;
            if ($code[0] !== '6' && $code[0] !== '7') {
                continue;
            }
            $bal = ($per[$code]['debit'] ?? 0) - ($per[$code]['credit'] ?? 0) + ($elimPer['lines'][$code]['debit'] ?? 0) - ($elimPer['lines'][$code]['credit'] ?? 0);
            $row = ['account' => $code, 'name' => $names[$code] ?? '?', 'amount' => $code[0] === '7' ? -$bal : $bal];
            if ($row['amount'] != 0) {
                $code[0] === '7' ? $rev[] = $row : $exp[] = $row;
            }
        }
        usort($rev, fn ($a, $b) => strcmp($a['account'], $b['account']));
        usort($exp, fn ($a, $b) => strcmp($a['account'], $b['account']));
        $totalRev = array_sum(array_column($rev, 'amount'));
        $totalExp = array_sum(array_column($exp, 'amount'));

        // ---- Balance sheet (cumulative, after eliminations) ----
        $assets = $liab = [];
        $resultCum = 0;
        foreach ($tbRows as $r) {
            if ($r['account'][0] === '6' || $r['account'][0] === '7') {
                $resultCum += -$r['balance']; // profit positive
                continue;
            }
            if ($r['balance'] == 0) {
                continue;
            }
            $r['balance'] > 0 ? $assets[] = ['account' => $r['account'], 'name' => $r['name'], 'amount' => $r['balance']]
                : $liab[] = ['account' => $r['account'], 'name' => $r['name'], 'amount' => -$r['balance']];
        }
        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiab = array_sum(array_column($liab, 'amount')) + $resultCum;

        // ---- Translation ----
        $cc = fn (int|float $v) => $currency === self::BASE ? (int) $v : round($v / $closing, 2);
        $ac = fn (int|float $v) => $currency === self::BASE ? (int) $v : round($v / $average, 2);
        $mapRows = fn (array $rows, callable $f, string ...$keys) => array_map(function ($r) use ($f, $keys) {
            foreach ($keys as $k) {
                $r[$k] = $f($r[$k]);
            }

            return $r;
        }, $rows);

        $assetsT = $mapRows($assets, $cc, 'amount');
        $liabT = $mapRows($liab, $cc, 'amount');
        $resultT = $ac($resultCum);
        $cta = $currency === self::BASE ? 0 : round(array_sum(array_column($assetsT, 'amount')) - array_sum(array_column($liabT, 'amount')) - $resultT, 2);

        return [
            'from' => $from, 'to' => $to, 'currency' => $currency, 'closing_rate' => $closing, 'average_rate' => $average,
            'hotels' => $perHotel,
            'eliminations' => [
                'intercompany_services' => $elimPer['service_total'], 'ic_difference' => $elimCum['ic_difference'],
                'receivable' => $elimCum['receivable'], 'payable' => $elimCum['payable'],
            ],
            'trial_balance' => [
                'rows' => array_map(fn ($r) => $this->tr($r, $cc, ['debit', 'credit', 'elim_debit', 'elim_credit', 'balance']), $tbRows),
                'total_debit' => $cc($tbDebit), 'total_credit' => $cc($tbCredit), 'balanced' => $tbDebit === $tbCredit,
            ],
            'income_statement' => [
                'revenue' => $mapRows($rev, $ac, 'amount'), 'expenses' => $mapRows($exp, $ac, 'amount'),
                'total_revenue' => $ac($totalRev), 'total_expenses' => $ac($totalExp), 'result' => $ac($totalRev - $totalExp),
            ],
            'balance_sheet' => [
                'assets' => $assetsT, 'liabilities_equity' => $liabT, 'result' => $resultT, 'cta' => $cta,
                'total_assets' => round(array_sum(array_column($assetsT, 'amount')), 2),
                'total_liabilities_equity' => round(array_sum(array_column($liabT, 'amount')) + $resultT + $cta, 2),
            ],
        ];
    }

    private function tr(array $r, callable $f, array $keys): array
    {
        foreach ($keys as $k) {
            $r[$k] = $f($r[$k]);
        }

        return $r;
    }

    /** @return array{0: float, 1: float} */
    private function rates(string $currency, string $to, ?float $closing, ?float $average): array
    {
        if ($currency === self::BASE) {
            return [1.0, 1.0];
        }
        $closing ??= DB::table('exchange_rates')->where(['from' => $currency, 'to' => self::BASE])->where('date', '<=', $to)->orderByDesc('date')->value('rate');
        if (! $closing || (float) $closing <= 0) {
            throw new DomainException("No closing rate for $currency; pass closing_rate.", 'missing-rate');
        }

        return [(float) $closing, (float) ($average ?: $closing)];
    }

    /** @return array<string, array{debit:int, credit:int}> summed over hotels */
    private function balances(array $hotelIds, ?string $from, ?string $to): array
    {
        $out = [];
        $rows = DB::table('journal_lines')->whereIn('hotel_id', $hotelIds)
            ->when($from, fn ($q, $v) => $q->where('date', '>=', $v))->when($to, fn ($q, $v) => $q->where('date', '<=', $v))
            ->selectRaw('account_code, sum(debit) as d, sum(credit) as c')->groupBy('account_code')->get();
        foreach ($rows as $r) {
            $out[$r->account_code] = ['debit' => (int) $r->d, 'credit' => (int) $r->c];
        }

        return $out;
    }

    /** Elimination lines keyed by account: ['debit' =>, 'credit' =>]. */
    private function eliminations(array $hotelIds, ?string $from, ?string $to, array $balances): array
    {
        $lines = [];
        $add = function (string $code, int $debit, int $credit) use (&$lines) {
            $lines[$code]['debit'] = ($lines[$code]['debit'] ?? 0) + $debit;
            $lines[$code]['credit'] = ($lines[$code]['credit'] ?? 0) + $credit;
        };

        $service = DB::table('intercompany_transactions')->whereIn('from_hotel_id', $hotelIds)->whereIn('to_hotel_id', $hotelIds)
            ->where('kind', 'service')->where('status', 'posted')
            ->when($from, fn ($q, $v) => $q->where('date', '>=', $v))->when($to, fn ($q, $v) => $q->where('date', '<=', $to))
            ->selectRaw('revenue_account, expense_account, sum(amount) as a')->groupBy('revenue_account', 'expense_account')->get();
        $serviceTotal = 0;
        foreach ($service as $s) {
            $add($s->revenue_account, (int) $s->a, 0);   // remove intra-group revenue
            $add($s->expense_account, 0, (int) $s->a);   // remove intra-group expense
            $serviceTotal += (int) $s->a;
        }

        // Current accounts: only meaningful in cumulative mode (no $from).
        $rec = $balances[Intercompany::RECEIVABLE] ?? ['debit' => 0, 'credit' => 0];
        $pay = $balances[Intercompany::PAYABLE] ?? ['debit' => 0, 'credit' => 0];
        $receivable = $rec['debit'] - $rec['credit'];   // debit balance
        $payable = $pay['credit'] - $pay['debit'];      // credit balance
        $diff = 0;
        if ($from === null) {
            $add(Intercompany::RECEIVABLE, 0, $receivable);
            $add(Intercompany::PAYABLE, $payable, 0);
            $diff = $receivable - $payable; // receivable eliminated (credit) vs payable eliminated (debit)
            if ($diff !== 0) {
                $diff > 0 ? $add('IC-DIFF', $diff, 0) : $add('IC-DIFF', 0, -$diff);
            }
        }

        return ['lines' => $lines, 'service_total' => $serviceTotal, 'ic_difference' => $diff, 'receivable' => $receivable, 'payable' => $payable];
    }
}

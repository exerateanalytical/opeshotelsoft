<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\FxRevaluation as Run;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revalues open foreign-currency receivables and payables (class 4 lines that
 * carry a currency) at a closing rate. The difference between the FCFA book
 * value and foreign balance × closing rate is posted to 776 (gain) or 676 (loss).
 */
class FxRevaluation
{
    public const BASE = 'XAF';

    public function __construct(private Ledger $ledger, private EventBus $bus) {}

    /** Open positions per account, partner and currency at $date. */
    public function positions(string $date): array
    {
        return DB::table('journal_lines')
            ->where('hotel_id', app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId())
            ->whereNotNull('currency')->where('currency', '<>', self::BASE)
            ->where('account_code', 'like', '4%')
            ->where('date', '<=', $date)
            ->selectRaw('account_code, partner_type, partner_id, currency,
                sum(case when debit > 0 then coalesce(fx_amount, 0) else -coalesce(fx_amount, 0) end) as fx_balance,
                sum(debit) - sum(credit) as book_balance')
            ->groupBy('account_code', 'partner_type', 'partner_id', 'currency')
            ->orderBy('account_code')->get()
            ->map(fn ($r) => ['account' => $r->account_code, 'partner_type' => $r->partner_type, 'partner_id' => $r->partner_id, 'currency' => $r->currency,
                'fx_balance' => round((float) $r->fx_balance, 2), 'book_balance' => (int) $r->book_balance])
            ->filter(fn ($p) => $p['fx_balance'] != 0.0 || $p['book_balance'] !== 0)
            ->values()->all();
    }

    /** Rates: request parameter first, then an admin `currencies` table (W01) if present, then exchange_rates. */
    public function resolveRates(array $currencies, string $date, array $given = []): array
    {
        $rates = [];
        foreach ($currencies as $cur) {
            $rate = $given[$cur] ?? null;
            if ($rate === null && Schema::hasTable('currencies')) {
                $col = collect(['exchange_rate', 'rate', 'rate_to_base'])->first(fn ($c) => Schema::hasColumn('currencies', $c));
                $codeCol = Schema::hasColumn('currencies', 'code') ? 'code' : null;
                if ($col && $codeCol) {
                    $rate = DB::table('currencies')->where($codeCol, $cur)->value($col);
                }
            }
            $rate ??= DB::table('exchange_rates')->where(['from' => $cur, 'to' => self::BASE])->where('date', '<=', $date)->orderByDesc('date')->value('rate');
            if ($rate === null || (float) $rate <= 0) {
                throw new DomainException("No closing rate for $cur; pass rates[$cur].", 'missing-rate');
            }
            $rates[$cur] = (float) $rate;
        }

        return $rates;
    }

    public function preview(string $date, array $given = []): array
    {
        $positions = $this->positions($date);
        $rates = $this->resolveRates(array_values(array_unique(array_column($positions, 'currency'))), $date, $given);
        foreach ($positions as &$p) {
            $p['rate'] = $rates[$p['currency']];
            $p['revalued'] = (int) round($p['fx_balance'] * $p['rate']);
            $p['difference'] = $p['revalued'] - $p['book_balance'];
        }

        return ['date' => $date, 'rates' => $rates, 'positions' => $positions,
            'gain' => array_sum(array_map(fn ($p) => max(0, $p['difference']), $positions)),
            'loss' => array_sum(array_map(fn ($p) => max(0, -$p['difference']), $positions))];
    }

    public function run(string $date, array $given = []): Run
    {
        $preview = $this->preview($date, $given);

        return DB::transaction(function () use ($preview, $date) {
            $lines = [];
            foreach ($preview['positions'] as $p) {
                if ($p['difference'] === 0) {
                    continue;
                }
                $meta = ['partner_type' => $p['partner_type'], 'partner_id' => $p['partner_id'], 'currency' => $p['currency'], 'fx_amount' => 0, 'fx_rate' => $p['rate'], 'description' => "Réévaluation {$p['currency']} @ {$p['rate']}"];
                $lines[] = [$p['account'], $p['difference'], $meta];
                $lines[] = [$p['difference'] > 0 ? C::role('fx_gain') : C::role('fx_loss'), -$p['difference']];
            }
            $entry = $lines ? $this->ledger->post('OD', Carbon::parse($date), "Réévaluation des créances et dettes en devises au $date", $lines, ['source_type' => 'fx_revaluation']) : null;
            $run = Run::create(['date' => $date, 'rates' => $preview['rates'], 'gain' => $preview['gain'], 'loss' => $preview['loss'], 'journal_entry_id' => $entry?->id, 'details' => $preview['positions']]);
            $this->bus->record('accounting.fx_revalued', ['id' => $run->id, 'gain' => $run->gain, 'loss' => $run->loss], 'fx_revaluation', $run->id);

            return $run;
        });
    }
}

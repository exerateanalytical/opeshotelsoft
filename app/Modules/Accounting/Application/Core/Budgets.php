<?php

namespace App\Modules\Accounting\Application\Core;

use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Budgets by account / department / month for one hotel and one year, with approval, and variance reports
 * (actual vs budget, forecast). Amounts use the natural sign: revenue and expenses are both positive.
 * Favourable variance is positive: revenue = actual - budget, expense = budget - actual.
 */
class Budgets
{
    public function __construct(private TenantContext $ctx, private AuditTrail $audit) {}

    public function list(?int $year = null): array
    {
        return DB::table('budgets')->where('hotel_id', $this->ctx->requireHotelId())->when($year, fn ($q, $v) => $q->where('year', $v))
            ->orderByDesc('year')->orderBy('name')->get()->map(fn ($b) => (array) $b + ['total' => $this->totals($b->id)])->all();
    }

    public function show(int $id): array
    {
        $b = $this->find($id);
        $b['lines'] = DB::table('budget_lines')->where('budget_id', $id)->orderBy('account_code')->orderBy('department')->orderBy('month')->get()->all();

        return $b;
    }

    /** Create or replace the lines of a draft budget. $lines: [account, department?, month, amount]. */
    public function save(string $name, int $year, array $lines, ?int $id = null): array
    {
        $h = $this->ctx->requireHotelId();
        $accounts = DB::table('accounts')->where('hotel_id', $h)->pluck('code')->flip();
        foreach ($lines as $l) {
            if (! isset($accounts[$l['account']]) || ! in_array($l['account'][0], ['6', '7'], true)) {
                throw new DomainException("Budget accounts must be existing class 6 or 7 accounts: {$l['account']}.", 'invalid-account');
            }
        }

        return DB::transaction(function () use ($id, $name, $year, $lines, $h) {
            if ($id) {
                $b = $this->find($id);
                if ($b['status'] !== 'draft') {
                    throw new DomainException('An approved budget is frozen; create a new version instead.', 'budget-frozen');
                }
                DB::table('budgets')->where('id', $id)->update(['name' => $name, 'year' => $year, 'updated_at' => now()]);
                DB::table('budget_lines')->where('budget_id', $id)->delete();
            } else {
                if (DB::table('budgets')->where(['hotel_id' => $h, 'year' => $year, 'name' => $name])->exists()) {
                    throw new DomainException('A budget with this name already exists for this year.', 'duplicate-budget');
                }
                $id = DB::table('budgets')->insertGetId(['group_id' => $this->ctx->groupId(), 'hotel_id' => $h, 'name' => $name, 'year' => $year, 'status' => 'draft',
                    'created_by' => $this->ctx->actor()?->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('budget_lines')->insert(array_map(fn ($l) => [
                'budget_id' => $id, 'hotel_id' => $h, 'account_code' => $l['account'], 'department' => $l['department'] ?? null,
                'month' => (int) $l['month'], 'amount' => (int) $l['amount'],
            ], $lines));
            $this->audit->record('budget_saved', 'budget', $id, ['name' => $name, 'year' => $year, 'lines' => count($lines)]);

            return $this->show($id);
        });
    }

    public function approve(int $id): array
    {
        $b = $this->find($id);
        if ($b['status'] === 'approved') {
            return $b;
        }
        if (! DB::table('budget_lines')->where('budget_id', $id)->exists()) {
            throw new DomainException('An empty budget cannot be approved.', 'empty-budget');
        }
        DB::table('budgets')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $this->ctx->actor()?->id, 'approved_at' => now()]);
        $this->audit->record('budget_approved', 'budget', $id, ['name' => $b['name']]);

        return $this->find($id);
    }

    /**
     * Actual vs budget.
     * $by: account | department | month. $through: last month included in "actual" (default: all 12).
     * Forecast = actual through $through + (budget for remaining months [method budget] or average monthly actual [method run_rate]).
     */
    public function variance(int $id, string $by = 'account', int $through = 12, string $method = 'budget'): array
    {
        $b = $this->find($id);
        $h = $this->ctx->requireHotelId();
        $through = max(1, min(12, $through));
        $names = DB::table('accounts')->where('hotel_id', $h)->pluck('name', 'code');

        $budget = DB::table('budget_lines')->where('budget_id', $id)->get();
        $actual = DB::table('journal_lines')->where('hotel_id', $h)->whereBetween('date', [$b['year'].'-01-01', $b['year'].'-12-31'])
            ->whereRaw("substr(account_code,1,1) in ('6','7')")
            ->selectRaw("account_code, coalesce(department,'') as department, extract(month from date)::int as month, sum(debit) as debit, sum(credit) as credit")
            ->groupByRaw("account_code, coalesce(department,''), extract(month from date)")->get();

        $key = fn (string $account, ?string $dept, int $month) => match ($by) {
            'department' => (string) $dept,
            'month' => (string) $month,
            default => $account,
        };
        $rows = [];
        $touch = function (string $k, string $account, ?string $dept, int $month) use (&$rows, $by, $names) {
            return $rows[$k] ??= ['key' => $k, 'account' => $by === 'account' ? $account : null, 'name' => $by === 'account' ? ($names[$account] ?? '?') : null,
                'department' => $by === 'department' ? $k : null, 'month' => $by === 'month' ? (int) $k : null, 'type' => $account[0] === '7' ? 'revenue' : 'expense',
                'budget_ytd' => 0, 'budget_full' => 0, 'actual_ytd' => 0, 'actual_full' => 0];
        };
        // For non-account groupings the sign convention is per source row, so totals are netted by result below.
        foreach ($budget as $l) {
            $k = $key($l->account_code, $l->department, $l->month);
            $sign = $by === 'account' || $l->account_code[0] === '7' ? 1 : -1;
            $touch($k, $l->account_code, $l->department, $l->month);
            $rows[$k]['budget_full'] += $sign * (int) $l->amount;
            if ($l->month <= $through) {
                $rows[$k]['budget_ytd'] += $sign * (int) $l->amount;
            }
        }
        foreach ($actual as $a) {
            $is7 = $a->account_code[0] === '7';
            $amt = $is7 ? (int) $a->credit - (int) $a->debit : (int) $a->debit - (int) $a->credit;
            $sign = $by === 'account' || $is7 ? 1 : -1;
            $k = $key($a->account_code, $a->department === '' ? null : $a->department, (int) $a->month);
            $touch($k, $a->account_code, $a->department === '' ? null : $a->department, (int) $a->month);
            if ($a->month <= $through) {
                $rows[$k]['actual_ytd'] += $sign * $amt;
            }
            $rows[$k]['actual_full'] += $sign * $amt;
        }

        // Actual beyond $through is not "known" for forecasting; forecast is built from YTD actual.
        foreach ($rows as &$r) {
            $remainingBudget = $r['budget_full'] - $r['budget_ytd'];
            $r['forecast'] = $method === 'run_rate'
                ? (int) round($r['actual_ytd'] + ($r['actual_ytd'] / $through) * (12 - $through))
                : $r['actual_ytd'] + $remainingBudget;
            $expense = $r['type'] === 'expense' && $by === 'account';
            $r['variance'] = $expense ? $r['budget_ytd'] - $r['actual_ytd'] : $r['actual_ytd'] - $r['budget_ytd'];
            $r['variance_pct'] = $r['budget_ytd'] != 0 ? round($r['variance'] / abs($r['budget_ytd']) * 100, 1) : null;
            $r['forecast_variance'] = $expense ? $r['budget_full'] - $r['forecast'] : $r['forecast'] - $r['budget_full'];
            unset($r['actual_full']);
        }
        unset($r);
        ksort($rows);

        $tot = ['revenue' => ['budget' => 0, 'actual' => 0, 'forecast' => 0, 'budget_full' => 0], 'expense' => ['budget' => 0, 'actual' => 0, 'forecast' => 0, 'budget_full' => 0]];
        foreach ($this->byType($id, $b['year'], $through, $method) as $type => $v) {
            $tot[$type] = $v;
        }

        return ['budget' => $b, 'by' => $by, 'through' => $through, 'method' => $method, 'rows' => array_values($rows),
            'totals' => $tot + ['result' => [
                'budget' => $tot['revenue']['budget'] - $tot['expense']['budget'],
                'actual' => $tot['revenue']['actual'] - $tot['expense']['actual'],
                'forecast' => $tot['revenue']['forecast'] - $tot['expense']['forecast'],
                'budget_full' => $tot['revenue']['budget_full'] - $tot['expense']['budget_full'],
            ]]];
    }

    /** Group view: variance totals of the approved budget (same name when given) of every hotel the user can see. */
    public function groupSummary(int $year, ?string $name = null): array
    {
        $out = [];
        foreach (HotelScope::each() as $hotel) {
            $q = DB::table('budgets')->where(['hotel_id' => $hotel->id, 'year' => $year, 'status' => 'approved'])->when($name, fn ($q, $v) => $q->where('name', $v))->orderByDesc('id')->first();
            if (! $q) {
                continue;
            }
            $v = $this->variance($q->id, 'account', (int) min(12, max(1, now()->year === $year ? now()->month : 12)));
            $out[] = ['hotel' => ['id' => $hotel->id, 'code' => $hotel->code, 'name' => $hotel->name], 'budget' => $q->name, 'totals' => $v['totals']];
        }

        return ['year' => $year, 'hotels' => $out];
    }

    private function byType(int $id, int $year, int $through, string $method): array
    {
        $h = $this->ctx->requireHotelId();
        $out = [];
        foreach (['revenue' => '7', 'expense' => '6'] as $type => $cls) {
            $bud = DB::table('budget_lines')->where('budget_id', $id)->where('account_code', 'like', "$cls%")
                ->selectRaw('sum(case when month <= ? then amount else 0 end) as ytd, sum(amount) as fullyear', [$through])->first();
            $act = DB::table('journal_lines')->where('hotel_id', $h)->whereBetween('date', [$year.'-01-01', $year.'-12-31'])->where('account_code', 'like', "$cls%")
                ->whereRaw('extract(month from date) <= ?', [$through])->selectRaw('sum(debit) as d, sum(credit) as c')->first();
            $actual = $cls === '7' ? (int) $act->c - (int) $act->d : (int) $act->d - (int) $act->c;
            $budYtd = (int) $bud->ytd;
            $full = (int) $bud->fullyear;
            $out[$type] = ['budget' => $budYtd, 'actual' => $actual, 'budget_full' => $full,
                'forecast' => $method === 'run_rate' ? (int) round($actual + ($actual / $through) * (12 - $through)) : $actual + ($full - $budYtd)];
        }

        return $out;
    }

    private function totals(int $id): int
    {
        return (int) DB::table('budget_lines')->where('budget_id', $id)->where('account_code', 'like', '7%')->sum('amount');
    }

    private function find(int $id): array
    {
        $b = DB::table('budgets')->where(['id' => $id, 'hotel_id' => $this->ctx->requireHotelId()])->first();
        if (! $b) {
            throw new DomainException('Budget not found.', 'not-found');
        }

        return (array) $b;
    }
}

<?php

namespace App\Modules\Accounting\Close;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 13-week cash forecast from the books: opening treasury (class 5), receivables by due date (overdue lands in week 1), approved supplier
 * invoices by due date (pending approval shown separately), payroll (net at month end, social and tax remittance on the 15th, from the
 * latest approved run), cash legs of recurring journals, and an optional budget layer (net budgeted result of the months touched, spread
 * by days; off by default because it overlaps with invoices already raised). Planning view only: nothing is posted.
 */
class CashForecast
{
    use Scoped;

    public function __construct(TenantContext $ctx, private Ledger $ledger)
    {
        $this->ctx = $ctx;
    }

    public function build(?string $start = null, array $opts = []): array
    {
        $start = Carbon::parse($start ?? Hotel::find($this->hotelId())->business_date)->startOfDay();
        $weeks = max(1, min(26, (int) ($opts['weeks'] ?? 13)));
        $end = $start->copy()->addDays($weeks * 7 - 1);
        $collect = (float) ($opts['collection_pct'] ?? 100) / 100;
        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $buckets[$i] = ['week' => $i + 1, 'from' => $start->copy()->addDays($i * 7)->toDateString(), 'to' => $start->copy()->addDays($i * 7 + 6)->toDateString(),
                'in' => ['receivables' => 0, 'recurring_in' => 0, 'budget_in' => 0], 'out' => ['payables' => 0, 'payables_pending' => 0, 'payroll' => 0, 'payroll_taxes' => 0, 'recurring_out' => 0, 'budget_out' => 0]];
        }
        $slot = function (Carbon|string $date) use ($start, $weeks): ?int {
            $d = Carbon::parse($date)->startOfDay();
            $i = $d->lt($start) ? 0 : intdiv((int) $start->diffInDays($d), 7);

            return $i < $weeks ? $i : null;
        };

        // Opening treasury.
        $opening = (int) array_sum(array_column(array_filter($this->ledger->balances(null, $start->copy()->subDay()->toDateString()), fn ($k) => ((string) $k)[0] === '5', ARRAY_FILTER_USE_KEY), 'balance'));

        // Receivables.
        $overdue = 0;
        foreach ($this->table('invoices')->where('kind', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->whereRaw('total > paid')->get() as $i) {
            $due = Carbon::parse($i->due_date ?? $i->issue_date);
            if (($k = $slot($due)) !== null) {
                $buckets[$k]['in']['receivables'] += (int) round(($i->total - $i->paid) * $collect);
                $overdue += $due->lt($start) ? (int) round(($i->total - $i->paid) * $collect) : 0;
            }
        }

        // Payables.
        $payablesPending = 0;
        foreach ($this->table('supplier_invoices')->whereIn('status', ['pending', 'approved', 'partially_paid'])->whereRaw('total > paid')->get() as $i) {
            $due = Carbon::parse($i->due_date ?? Carbon::parse($i->date)->addDays(30));
            if (($k = $slot($due)) === null) {
                continue;
            }
            $key = $i->status === 'pending' ? 'payables_pending' : 'payables';
            if ($key === 'payables_pending' && ! ($opts['include_pending'] ?? true)) {
                continue;
            }
            $buckets[$k]['out'][$key] += (int) ($i->total - $i->paid);
            $payablesPending += $key === 'payables_pending' ? (int) ($i->total - $i->paid) : 0;
        }

        // Payroll from the latest approved or paid run.
        $run = $this->table('payroll_runs')->whereIn('status', ['approved', 'paid'])->orderByDesc('period')->first();
        $payrollNote = null;
        if ($run) {
            $remit = (int) ($run->employee_social + $run->employer_social + $run->taxes_withheld + $run->other_employer);
            for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonthNoOverflow()) {
                $payDay = $m->copy()->endOfMonth()->startOfDay();
                $paidAlready = $this->table('payroll_runs')->where('period', $m->format('Y-m'))->where('status', 'paid')->exists();
                if (! $paidAlready && $payDay->gte($start) && $payDay->lte($end) && ($k = $slot($payDay)) !== null) {
                    $buckets[$k]['out']['payroll'] += (int) $run->net;
                }
                $remitDay = $m->copy()->addMonthNoOverflow()->startOfMonth()->addDays(14);
                if ($remitDay->gte($start) && $remitDay->lte($end) && ($k = $slot($remitDay)) !== null) {
                    $buckets[$k]['out']['payroll_taxes'] += $remit;
                }
            }
            $payrollNote = "Based on payroll run {$run->period}.";
        }

        // Recurring journals: only lines on cash accounts (class 5) move cash.
        foreach ($this->table('recurring_journals')->where('active', true)->get() as $r) {
            $lines = json_decode($r->lines, true) ?: [];
            $cash = 0;
            foreach ($lines as $l) {
                if (str_starts_with((string) ($l['account'] ?? ''), '5')) {
                    $cash += (int) ($l['debit'] ?? 0) - (int) ($l['credit'] ?? 0);
                }
            }
            if ($cash === 0) {
                continue;
            }
            for ($d = Carbon::parse($r->next_date); $d->lte($end); $d = match ($r->frequency) { 'quarterly' => $d->copy()->addMonthsNoOverflow(3), 'yearly' => $d->copy()->addYearNoOverflow(), default => $d->copy()->addMonthNoOverflow() }) {
                if ($r->end_date && $d->gt(Carbon::parse($r->end_date))) {
                    break;
                }
                if ($d->gte($start) && ($k = $slot($d)) !== null) {
                    $buckets[$k][$cash > 0 ? 'in' : 'out'][$cash > 0 ? 'recurring_in' : 'recurring_out'] += abs($cash);
                }
            }
        }

        // Budget layer (optional).
        $budgetNote = null;
        if ($opts['include_budget'] ?? false) {
            foreach (range($start->year, $end->year) as $year) {
                $budget = $this->table('budgets')->where(['year' => $year, 'status' => 'approved'])->orderByDesc('id')->first();
                if (! $budget) {
                    continue;
                }
                for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonthNoOverflow()) {
                    if ($m->year !== $year) {
                        continue;
                    }
                    $lines = DB::table('budget_lines')->where(['budget_id' => $budget->id, 'month' => $m->month])->get();
                    $rev = (int) $lines->filter(fn ($l) => str_starts_with($l->account_code, '7'))->sum('amount');
                    $exp = (int) $lines->filter(fn ($l) => str_starts_with($l->account_code, '6'))->sum('amount');
                    $days = $m->daysInMonth;
                    foreach ($buckets as $i => $b) {
                        $overlap = 0;
                        for ($d = Carbon::parse($b['from']); $d->lte(Carbon::parse($b['to'])); $d->addDay()) {
                            $overlap += ($d->format('Y-m') === $m->format('Y-m')) ? 1 : 0;
                        }
                        $buckets[$i]['in']['budget_in'] += (int) round($rev * $overlap / $days);
                        $buckets[$i]['out']['budget_out'] += (int) round($exp * $overlap / $days);
                    }
                }
                $budgetNote = 'Budget layer: monthly budgeted revenue and expenses spread by day; overlaps with invoices already counted.';
            }
        }

        $running = $opening;
        $min = ['week' => null, 'balance' => PHP_INT_MAX];
        foreach ($buckets as $i => &$b) {
            $b['total_in'] = array_sum($b['in']);
            $b['total_out'] = array_sum($b['out']);
            $b['net'] = $b['total_in'] - $b['total_out'];
            $b['opening'] = $running;
            $running += $b['net'];
            $b['closing'] = $running;
            if ($running < $min['balance']) {
                $min = ['week' => $b['week'], 'balance' => $running];
            }
        }
        unset($b);

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'weeks' => array_values($buckets), 'opening' => $opening, 'closing' => $running,
            'lowest' => $min, 'first_negative_week' => collect($buckets)->first(fn ($b) => $b['closing'] < 0)['week'] ?? null,
            'overdue_receivables_in_week_1' => $overdue, 'pending_approval_payables' => $payablesPending,
            'notes' => array_values(array_filter([$payrollNote, $budgetNote, 'Planning view: nothing is posted.'])), 'options' => ['weeks' => $weeks, 'collection_pct' => $collect * 100]];
    }
}

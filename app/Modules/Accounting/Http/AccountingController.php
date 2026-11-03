<?php

namespace App\Modules\Accounting\Http;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingController
{
    public function __construct(private Ledger $ledger, private TenantContext $ctx) {}

    public function accounts(Request $request)
    {
        $this->can($request, 'view');

        return Account::orderBy('code')->get();
    }

    public function saveAccount(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[1-9][0-9]{1,19}$/'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense', 'off_balance'])],
            'postable' => ['sometimes', 'boolean'], 'reconcilable' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'],
        ]);
        $data['class'] = (int) $data['code'][0];

        return Account::updateOrCreate(['code' => $data['code']], $data);
    }

    public function entries(Request $request)
    {
        $this->can($request, 'view');

        return JournalEntry::with('lines')
            ->when($request->query('journal'), fn ($q, $v) => $q->where('journal_code', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($request->query('account'), fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('account_code', $v)))
            ->orderByDesc('date')->orderByDesc('id')->paginate(50);
    }

    /** Journal codes configured or in use, with names. */
    public function journals(Request $request)
    {
        $this->can($request, 'view');
        $configured = Journal::pluck('name', 'code');
        $used = JournalEntry::query()->distinct()->pluck('journal_code');
        $codes = $configured->keys()->merge($used)->unique()->sort()->values();

        return $codes->map(fn ($c) => ['code' => $c, 'name' => $configured[$c] ?? $c])->all();
    }

    public function entry(Request $request, JournalEntry $entry)
    {
        $this->can($request, 'view');

        return $entry->load('lines');
    }

    /** Manual journal entry (OD). Needs accounting.post_manual. */
    public function postManual(Request $request)
    {
        abort_unless($request->user()->can('accounting.post_manual'), 403);
        $data = $request->validate([
            'journal' => ['required', 'string', 'max:10'],
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:250'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account' => ['required', 'string'],
            'lines.*.debit' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.partner_type' => ['nullable', 'string'],
            'lines.*.partner_id' => ['nullable', 'integer'],
            'lines.*.department' => ['nullable', 'string'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
            'lines.*.fx_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        return response()->json($this->ledger->post($data['journal'], $data['date'], $data['description'], $data['lines'], [
            'source_type' => 'manual', 'approved_by' => $request->user()->id,
        ])->load('lines'), 201);
    }

    public function reverse(Request $request, JournalEntry $entry)
    {
        abort_unless($request->user()->can('accounting.post_manual'), 403);
        $data = $request->validate(['reason' => ['required', 'string'], 'date' => ['nullable', 'date']]);

        return response()->json($this->ledger->reverse($entry, $data['reason'], $data['date'] ?? null)->load('lines'), 201);
    }

    public function trialBalance(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $balances = $this->ledger->balances($data['from'] ?? null, $data['to'] ?? null);
        $names = Account::pluck('name', 'code');
        $rows = [];
        foreach ($balances as $code => $b) {
            $rows[] = ['account' => $code, 'name' => $names[$code] ?? '?'] + $b;
        }

        return [
            'rows' => $rows,
            'total_debit' => array_sum(array_column($rows, 'debit')),
            'total_credit' => array_sum(array_column($rows, 'credit')),
        ];
    }

    public function generalLedger(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['account' => ['required', 'string'], 'from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $opening = $this->ledger->balances(null, Carbon::parse($data['from'])->subDay()->toDateString(), [$data['account']])[$data['account']]['balance'] ?? 0;
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.hotel_id', $this->ctx->requireHotelId())->where('l.account_code', $data['account'])
            ->whereBetween('l.date', [$data['from'], $data['to']])
            ->orderBy('l.date')->orderBy('l.id')
            ->get(['l.date', 'e.number', 'e.journal_code', 'e.description', 'l.debit', 'l.credit', 'l.partner_type', 'l.partner_id']);
        $running = $opening;
        foreach ($lines as $l) {
            $running += $l->debit - $l->credit;
            $l->balance = $running;
        }

        return ['account' => $data['account'], 'opening' => $opening, 'lines' => $lines, 'closing' => $running];
    }

    /** Income statement (compte de résultat): classes 6, 7, 8. */
    public function incomeStatement(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return $this->statement($data['from'], $data['to']);
    }

    /** Balance sheet (bilan): classes 1-5 at a date, current result included in equity. */
    public function balanceSheet(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['date' => ['required', 'date']]);
        $balances = $this->ledger->balances(null, $data['date']);
        $names = Account::pluck('name', 'code');
        $types = Account::pluck('type', 'code');

        $assets = $liabilities = [];
        $result = 0;
        foreach ($balances as $code => $b) {
            $code = (string) $code; // numeric array keys come back as ints
            $class = (int) $code[0];
            if ($class >= 6) {
                $result -= $b['balance'];

                continue;
            }
            if ($b['balance'] === 0) {
                continue;
            }
            // Receivable/payable accounts are classified by their balance sign.
            $isAsset = $b['balance'] > 0 && ! in_array($types[$code] ?? '', ['equity'], true);
            $name = $names[$code] ?? '?';
            $isAsset
                ? $assets[] = ['account' => $code, 'name' => $name, 'amount' => $b['balance']]
                : $liabilities[] = ['account' => $code, 'name' => $name, 'amount' => -$b['balance']];
        }
        if ($result !== 0) {
            $liabilities[] = ['account' => '131000', 'name' => "Résultat de l'exercice (en cours)", 'amount' => $result];
        }

        return [
            'date' => $data['date'],
            'assets' => $assets, 'total_assets' => array_sum(array_column($assets, 'amount')),
            'liabilities_and_equity' => $liabilities, 'total_liabilities_and_equity' => array_sum(array_column($liabilities, 'amount')),
        ];
    }

    /** VAT return: collected vs deductible for the period. */
    public function vatReport(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $b = $this->ledger->balances($data['from'], $data['to'], [C::role('vat_collected'), C::role('vat_deductible'), C::role('tourist_tax')]);
        $collected = -($b[C::role('vat_collected')]['balance'] ?? 0);
        $deductible = $b[C::role('vat_deductible')]['balance'] ?? 0;

        return [
            'from' => $data['from'], 'to' => $data['to'],
            'vat_collected' => $collected, 'vat_deductible' => $deductible, 'vat_payable' => $collected - $deductible,
            'tourist_tax_collected' => -($b[C::role('tourist_tax')]['balance'] ?? 0),
        ];
    }

    /** USALI-style departmental revenue and cost. */
    public function departmental(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return DB::table('journal_lines')->where('hotel_id', $this->ctx->requireHotelId())
            ->whereBetween('date', [$data['from'], $data['to']])->whereNotNull('department')
            ->selectRaw("department, sum(case when account_code like '7%' then credit - debit else 0 end) as revenue, sum(case when account_code like '6%' then debit - credit else 0 end) as cost")
            ->groupBy('department')->get();
    }

    /** Ageing of city-ledger (company) receivables. */
    public function receivablesAgeing(Request $request)
    {
        $this->can($request, 'view');
        $today = Hotel::findOrFail($this->ctx->requireHotelId())->business_date;

        return DB::table('invoices')->where('hotel_id', $this->ctx->requireHotelId())
            ->whereNotNull('company_id')->whereIn('status', ['issued', 'partially_paid'])
            ->get(['id', 'number', 'company_id', 'bill_to_name', 'issue_date', 'due_date', 'total', 'paid'])
            ->map(function ($i) use ($today) {
                $days = $i->due_date ? Carbon::parse($i->due_date)->diffInDays($today, false) : 0;
                $i->outstanding = $i->total - $i->paid;
                $i->bucket = match (true) {
                    $days <= 0 => 'current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+',
                };

                return $i;
            })->groupBy('bucket');
    }

    public function periods(Request $request)
    {
        $this->can($request, 'view');

        return DB::table('fiscal_periods')->where('hotel_id', $this->ctx->requireHotelId())->orderByDesc('year')->orderByDesc('month')->get();
    }

    /** Close (lock) a month. No postings may then be dated inside it. */
    public function closePeriod(Request $request, EventBus $bus)
    {
        abort_unless($request->user()->can('accounting.close_period'), 403);
        $data = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'between:1,12']]);
        $hotelId = $this->ctx->requireHotelId();
        $end = Carbon::create($data['year'], $data['month'])->endOfMonth();
        $tb = $this->ledger->balances(null, $end->toDateString());
        if (array_sum(array_column($tb, 'debit')) !== array_sum(array_column($tb, 'credit'))) {
            throw new DomainException('Trial balance does not balance; the period cannot be closed.');
        }
        $suspense = $tb[C::role('suspense')]['balance'] ?? 0;
        if ($suspense !== 0) {
            throw new DomainException('The suspense account (471) must be cleared before closing.');
        }
        DB::table('fiscal_periods')->updateOrInsert(
            ['hotel_id' => $hotelId, 'year' => $data['year'], 'month' => $data['month']],
            ['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id],
        );
        $bus->record('accounting.period_closed', $data, 'hotel', $hotelId);

        return ['status' => 'closed'] + $data;
    }

    public function reopenPeriod(Request $request, EventBus $bus)
    {
        abort_unless($request->user()->hasRole(['group-owner', 'finance-director']), 403, 'Only the finance director can reopen a period.');
        $data = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'between:1,12'], 'reason' => ['required', 'string']]);
        DB::table('fiscal_periods')->where(['hotel_id' => $this->ctx->requireHotelId(), 'year' => $data['year'], 'month' => $data['month']])->update(['status' => 'open']);
        $bus->record('accounting.period_reopened', $data, 'hotel', $this->ctx->requireHotelId());

        return ['status' => 'open'] + $data;
    }

    /** Group consolidation: income statement summed across all accessible hotels. */
    public function consolidated(Request $request)
    {
        abort_unless($request->user()->can('accounting.view') && $request->user()->all_hotels, 403);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $hotels = Hotel::all();
        $out = ['hotels' => [], 'total' => ['revenue' => 0, 'expenses' => 0, 'result' => 0]];
        foreach ($hotels as $hotel) {
            $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
            $this->ctx->set($hotel->group_id, $hotel->id, $saved[2], $saved[3]);
            try {
                $s = $this->statement($data['from'], $data['to']);
            } finally {
                $this->ctx->set(...$saved);
            }
            $out['hotels'][] = ['hotel' => $hotel->only(['id', 'code', 'name']), 'revenue' => $s['total_revenue'], 'expenses' => $s['total_expenses'], 'result' => $s['result']];
            $out['total']['revenue'] += $s['total_revenue'];
            $out['total']['expenses'] += $s['total_expenses'];
            $out['total']['result'] += $s['result'];
        }

        return $out;
    }

    private function statement(string $from, string $to): array
    {
        $balances = $this->ledger->balances($from, $to);
        $names = Account::pluck('name', 'code');
        $revenue = $expenses = [];
        foreach ($balances as $code => $b) {
            if ($code[0] === '7') {
                $revenue[] = ['account' => $code, 'name' => $names[$code] ?? '?', 'amount' => -$b['balance']];
            } elseif ($code[0] === '6') {
                $expenses[] = ['account' => $code, 'name' => $names[$code] ?? '?', 'amount' => $b['balance']];
            }
        }
        $tr = array_sum(array_column($revenue, 'amount'));
        $te = array_sum(array_column($expenses, 'amount'));

        return ['from' => $from, 'to' => $to, 'revenue' => $revenue, 'expenses' => $expenses, 'total_revenue' => $tr, 'total_expenses' => $te, 'result' => $tr - $te];
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('accounting.manage') || ($ability === 'view' && $u->can('accounting.view')), 403);
    }
}

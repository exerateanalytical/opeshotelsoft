<?php

namespace App\Modules\Accounting\Http\Core;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\Core\Budgets;
use App\Modules\Accounting\Application\Core\Consolidation;
use App\Modules\Accounting\Application\Core\Deferrals;
use App\Modules\Accounting\Application\Core\DimensionDefaults;
use App\Modules\Accounting\Application\Core\Intercompany;
use App\Modules\Accounting\Application\Core\ManualEntries;
use App\Modules\Accounting\Application\Core\MultiCurrencyRevaluation;
use App\Modules\Accounting\Application\Core\PeriodLocking;
use App\Modules\Accounting\Application\Core\RecurringJournals;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A-A core ledger endpoints (all under /api/v1/accounting/core). Every route checks a permission explicitly. */
class CoreController
{
    private function can(Request $r, string $perm): void
    {
        abort_unless($r->user()->can($perm), 403);
    }

    private function view(Request $r): void
    {
        $this->can($r, 'accounting.view');
    }

    // ---------------- Budgets ----------------

    public function budgets(Request $r, Budgets $b)
    {
        $this->view($r);

        return $b->list($r->integer('year') ?: null);
    }

    public function budget(Request $r, Budgets $b, int $id)
    {
        $this->view($r);

        return $b->show($id);
    }

    public function saveBudget(Request $r, Budgets $b)
    {
        $this->can($r, 'accounting.budgets');
        $d = $r->validate([
            'id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'year' => ['required', 'integer', 'between:2000,2100'],
            'lines' => ['required', 'array', 'min:1', 'max:5000'], 'lines.*.account' => ['required', 'string'], 'lines.*.department' => ['nullable', 'string', 'max:60'],
            'lines.*.month' => ['required', 'integer', 'between:1,12'], 'lines.*.amount' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($b->save($d['name'], $d['year'], $d['lines'], $d['id'] ?? null), 201);
    }

    public function approveBudget(Request $r, Budgets $b, int $id)
    {
        $this->can($r, 'accounting.budgets_approve');

        return $b->approve($id);
    }

    public function budgetVariance(Request $r, Budgets $b, int $id)
    {
        $this->view($r);
        $d = $r->validate(['by' => ['nullable', Rule::in(['account', 'department', 'month'])], 'through' => ['nullable', 'integer', 'between:1,12'], 'method' => ['nullable', Rule::in(['budget', 'run_rate'])]]);

        return $b->variance($id, $d['by'] ?? 'account', (int) ($d['through'] ?? 12), $d['method'] ?? 'budget');
    }

    public function budgetGroup(Request $r, Budgets $b)
    {
        abort_unless($r->user()->can('accounting.consolidate') && $r->user()->all_hotels, 403);
        $d = $r->validate(['year' => ['required', 'integer'], 'name' => ['nullable', 'string']]);

        return $b->groupSummary($d['year'], $d['name'] ?? null);
    }

    // ---------------- Dimensions ----------------

    public function dimensions(Request $r, DimensionDefaults $d)
    {
        $this->view($r);

        return ['dimensions' => $d->list(), 'defaults' => $d->defaults()];
    }

    public function saveDimension(Request $r, DimensionDefaults $d)
    {
        $this->can($r, 'accounting.dimensions');
        $v = $r->validate(['kind' => ['required', Rule::in(DimensionDefaults::KINDS)], 'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150'], 'active' => ['sometimes', 'boolean']]);

        return response()->json($d->save($v['kind'], $v['code'], $v['name'], $v['active'] ?? true), 201);
    }

    public function saveDimensionDefault(Request $r, DimensionDefaults $d)
    {
        $this->can($r, 'accounting.dimensions');
        $v = $r->validate(['department' => ['required', 'string', 'max:60'], 'outlet' => ['nullable', 'string', 'max:100'], 'project' => ['nullable', 'string', 'max:100'], 'cost_centre' => ['nullable', 'string', 'max:100']]);

        return response()->json($d->saveDefault($v['department'], $v['outlet'] ?? null, $v['project'] ?? null, $v['cost_centre'] ?? null), 201);
    }

    public function dimensionReport(Request $r, DimensionDefaults $d)
    {
        $this->view($r);
        $v = $r->validate(['by' => ['required', Rule::in(DimensionDefaults::KINDS)], 'from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return $d->report($v['by'], $v['from'], $v['to']);
    }

    // ---------------- Accruals, prepayments, recurring ----------------

    public function schedules(Request $r, Deferrals $s)
    {
        $this->view($r);

        return $s->list();
    }

    public function scheduleItems(Request $r, Deferrals $s, int $id)
    {
        $this->view($r);

        return $s->items($id);
    }

    public function createSchedule(Request $r, Deferrals $s)
    {
        $this->can($r, 'accounting.schedules');
        $v = $r->validate([
            'kind' => ['required', Rule::in(array_keys(Deferrals::KINDS))], 'description' => ['required', 'string', 'max:200'],
            'debit_account' => ['nullable', 'string'], 'credit_account' => ['nullable', 'string'], 'total' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'], 'months' => ['nullable', 'integer', 'between:1,120'],
            'department' => ['nullable', 'string', 'max:60'], 'outlet' => ['nullable', 'string', 'max:100'], 'project' => ['nullable', 'string', 'max:100'], 'cost_centre' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($s->create($v), 201);
    }

    public function cancelSchedule(Request $r, Deferrals $s, int $id)
    {
        $this->can($r, 'accounting.schedules');

        return $s->cancel($id);
    }

    public function runSchedules(Request $r, Deferrals $s, RecurringJournals $rj)
    {
        $this->can($r, 'accounting.schedules');
        $v = $r->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $v['as_of'] ?? now()->toDateString();

        return ['as_of' => $asOf, 'deferrals' => $s->run($asOf), 'recurring' => $rj->run($asOf)];
    }

    public function recurring(Request $r, RecurringJournals $rj)
    {
        $this->view($r);

        return $rj->list();
    }

    public function saveRecurring(Request $r, RecurringJournals $rj)
    {
        $this->can($r, 'accounting.schedules');
        $v = $r->validate([
            'id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:150'], 'journal' => ['required', 'string', 'max:10'],
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])], 'next_date' => ['required', 'date'], 'end_date' => ['nullable', 'date'], 'active' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:2'], 'lines.*.account' => ['required', 'string'], 'lines.*.debit' => ['nullable', 'integer', 'min:0'], 'lines.*.credit' => ['nullable', 'integer', 'min:0'],
            'lines.*.department' => ['nullable', 'string'], 'lines.*.outlet' => ['nullable', 'string'], 'lines.*.project' => ['nullable', 'string'], 'lines.*.cost_centre' => ['nullable', 'string'], 'lines.*.description' => ['nullable', 'string'],
        ]);

        return response()->json($rj->save($v, $v['id'] ?? null), 201);
    }

    // ---------------- Period locking with approval ----------------

    public function lockRequests(Request $r, PeriodLocking $p)
    {
        $this->view($r);

        return $p->list($r->query('status'));
    }

    public function requestLock(Request $r, PeriodLocking $p)
    {
        $this->can($r, 'accounting.close_period');
        $v = $r->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'between:1,12'], 'action' => ['required', Rule::in(['close', 'reopen'])], 'reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($p->request($v['year'], $v['month'], $v['action'], $v['reason'] ?? null), 201);
    }

    public function decideLock(Request $r, PeriodLocking $p, int $id)
    {
        $this->can($r, 'accounting.period_approve');
        $v = $r->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);

        return $p->decide($id, (bool) $v['approve'], $v['note'] ?? null);
    }

    // ---------------- Manual entries with attachments, audit ----------------

    public function postManual(Request $r, ManualEntries $m)
    {
        $this->can($r, 'accounting.post_manual');
        if (is_string($r->input('lines'))) {
            $r->merge(['lines' => json_decode($r->input('lines'), true) ?: []]);
        }
        $v = $r->validate([
            'journal' => ['required', 'string', 'max:10'], 'date' => ['required', 'date'], 'description' => ['required', 'string', 'max:250'],
            'lines' => ['required', 'array', 'min:2'], 'lines.*.account' => ['required', 'string'], 'lines.*.debit' => ['nullable', 'integer', 'min:0'], 'lines.*.credit' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string'], 'lines.*.partner_type' => ['nullable', 'string'], 'lines.*.partner_id' => ['nullable', 'integer'],
            'lines.*.department' => ['nullable', 'string'], 'lines.*.outlet' => ['nullable', 'string'], 'lines.*.project' => ['nullable', 'string'], 'lines.*.cost_centre' => ['nullable', 'string'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'], 'lines.*.fx_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'attachments' => ['required', 'array', 'min:1', 'max:10'], 'attachments.*' => ['file', 'max:'.ManualEntries::MAX_KB, 'mimes:pdf,png,jpg,jpeg,xlsx,csv,docx,txt'],
        ]);

        return response()->json($m->post($v['journal'], $v['date'], $v['description'], $v['lines'], $r->file('attachments', [])), 201);
    }

    public function attach(Request $r, ManualEntries $m, JournalEntry $entry)
    {
        $this->can($r, 'accounting.post_manual');
        $r->validate(['file' => ['required', 'file', 'max:'.ManualEntries::MAX_KB, 'mimes:pdf,png,jpg,jpeg,xlsx,csv,docx,txt']]);

        return response()->json($m->attach($entry, $r->file('file')), 201);
    }

    public function attachments(Request $r, ManualEntries $m, JournalEntry $entry)
    {
        $this->view($r);

        return $m->attachments($entry->id);
    }

    public function downloadAttachment(Request $r, ManualEntries $m, int $attachment)
    {
        $this->view($r);
        [$path, $name, $mime] = $m->download($attachment);

        return response()->download($path, $name, ['Content-Type' => $mime]);
    }

    public function reverse(Request $r, Ledger $ledger, JournalEntry $entry)
    {
        $this->can($r, 'accounting.post_manual');
        $v = $r->validate(['reason' => ['required', 'string', 'max:300'], 'date' => ['nullable', 'date']]);

        return response()->json($ledger->reverse($entry, $v['reason'], $v['date'] ?? null)->load('lines'), 201);
    }

    /** Full history of one entry: who posted, attachments, reversal. */
    public function entryAudit(Request $r, AuditTrail $a, ManualEntries $m, JournalEntry $entry)
    {
        $this->can($r, 'accounting.audit');

        return ['entry' => $entry->only(['id', 'number', 'journal_code', 'date', 'description', 'source_type', 'created_by', 'approved_by', 'reversed', 'reverses_entry_id', 'created_at']),
            'trail' => $a->for('journal_entry', $entry->id), 'attachments' => $m->attachments($entry->id)];
    }

    public function auditLog(Request $r, AuditTrail $a)
    {
        $this->can($r, 'accounting.audit');

        return $a->recent(min(500, max(1, $r->integer('limit', 100))), $r->query('subject_type'));
    }

    // ---------------- Intercompany and consolidation ----------------

    public function intercompany(Request $r, Intercompany $i)
    {
        $this->can($r, 'accounting.intercompany');

        return $i->list($r->query('from'), $r->query('to'));
    }

    public function postIntercompany(Request $r, Intercompany $i)
    {
        $this->can($r, 'accounting.intercompany');
        $v = $r->validate([
            'from_hotel_id' => ['required', 'integer'], 'to_hotel_id' => ['required', 'integer'], 'kind' => ['required', Rule::in(['service', 'funding', 'settlement'])],
            'date' => ['required', 'date'], 'amount' => ['required', 'integer', 'min:1'], 'description' => ['required', 'string', 'max:200'],
            'revenue_account' => ['nullable', 'string'], 'expense_account' => ['nullable', 'string'], 'treasury_account' => ['nullable', 'string'], 'department' => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json($i->post($v), 201);
    }

    public function reverseIntercompany(Request $r, Intercompany $i, int $id)
    {
        $this->can($r, 'accounting.intercompany');
        $v = $r->validate(['reason' => ['required', 'string', 'max:300']]);

        return response()->json($i->reverse($id, $v['reason']), 201);
    }

    public function consolidation(Request $r, Consolidation $c)
    {
        abort_unless($r->user()->can('accounting.consolidate') && $r->user()->all_hotels, 403);
        $v = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'currency' => ['nullable', 'string', 'size:3'],
            'closing_rate' => ['nullable', 'numeric', 'gt:0'], 'average_rate' => ['nullable', 'numeric', 'gt:0']]);

        return $c->build($v['from'], $v['to'], $v['currency'] ?? 'XAF', isset($v['closing_rate']) ? (float) $v['closing_rate'] : null, isset($v['average_rate']) ? (float) $v['average_rate'] : null);
    }

    // ---------------- Multi-currency ----------------

    public function fxExposure(Request $r, MultiCurrencyRevaluation $fx)
    {
        $this->view($r);
        $v = $r->validate(['date' => ['required', 'date'], 'rates' => ['nullable', 'array']]);

        return $fx->preview($v['date'], $v['rates'] ?? []);
    }

    public function fxMonthEnd(Request $r, MultiCurrencyRevaluation $fx)
    {
        $this->can($r, 'accounting.manage');
        $v = $r->validate(['date' => ['required', 'date']]);
        $res = $fx->monthEnd($v['date']);
        if ($res['status'] === 'skipped') {
            throw new DomainException($res['reason'], 'missing-rate');
        }

        return $res;
    }
}

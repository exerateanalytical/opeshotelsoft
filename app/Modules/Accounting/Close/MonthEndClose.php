<?php

namespace App\Modules\Accounting\Close;

use App\Models\User;
use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\Core\PeriodLocking;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Support\Scoped;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Month-end close checklist: templates (title, owner role, due offset, optional automatic check) are copied into a run for a month;
 * each task is signed off by its owner (or someone with accounting.close_manage). Automatic checks must pass to sign, unless an override
 * reason is given (audited). When every required task is signed the run is complete and a period close request is raised through the
 * existing four-eyes PeriodLocking, which is what finally locks the period.
 */
class MonthEndClose
{
    use Scoped;

    public const DEFAULT_TEMPLATES = [
        ['Rapprocher les comptes bancaires et mobile money', 'accountant', 3, 'bank_reconciled'],
        ['Valider ou rejeter les factures fournisseurs en attente', 'accountant', 3, 'ap_pending'],
        ['Passer les dotations aux amortissements', 'accountant', 4, 'depreciation'],
        ['Passer les écritures récurrentes et de régularisation', 'accountant', 4, 'recurring_done'],
        ['Valider la paie du mois', 'payroll-officer', 5, 'payroll_posted'],
        ['Déposer la déclaration de TVA', 'accountant', 5, 'vat_filed'],
        ['Apurer le compte d\'attente 471', 'accountant', 5, 'suspense_clear'],
        ['Contrôler la balance (équilibre débit / crédit)', 'finance-director', 6, 'trial_balance'],
        ['Revue des relances clients et créances douteuses', 'finance-director', 6, null],
        ['Revue du directeur financier et signature', 'finance-director', 7, null],
    ];

    public function __construct(TenantContext $ctx, private Ledger $ledger, private AuditTrail $audit, private PeriodLocking $locking)
    {
        $this->ctx = $ctx;
    }

    public function templates(): array
    {
        if (! $this->table('close_templates')->exists()) {
            foreach (self::DEFAULT_TEMPLATES as $i => [$title, $role, $offset, $check]) {
                DB::table('close_templates')->insert($this->tenant() + ['position' => ($i + 1) * 10, 'title' => $title, 'owner_role' => $role, 'due_offset_days' => $offset, 'auto_check' => $check, ...$this->stamp()]);
            }
        }

        return $this->table('close_templates')->orderBy('position')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function saveTemplate(array $d, ?int $id = null): array
    {
        $this->templates();
        $row = array_intersect_key($d, array_flip(['position', 'title', 'owner_role', 'due_offset_days', 'required', 'auto_check', 'active'])) + ['updated_at' => now()];
        if (! empty($row['auto_check']) && ! in_array($row['auto_check'], self::checks(), true)) {
            throw new DomainException('Unknown automatic check.', 'unknown-check');
        }
        if ($id) {
            $this->table('close_templates')->where('id', $id)->exists() || throw new DomainException('Template not found.', 'not-found');
            $this->table('close_templates')->where('id', $id)->update($row);
        } else {
            $id = DB::table('close_templates')->insertGetId($row + $this->tenant() + ['created_at' => now()]);
        }
        $this->audit->record('close_template_saved', 'close_template', $id, $row);

        return (array) DB::table('close_templates')->find($id);
    }

    public static function checks(): array
    {
        return ['trial_balance', 'bank_reconciled', 'ap_pending', 'depreciation', 'vat_filed', 'payroll_posted', 'suspense_clear', 'recurring_done'];
    }

    public function runs(): array
    {
        return $this->table('close_runs')->orderByDesc('year')->orderByDesc('month')->get()->map(fn ($r) => $this->summary($r))->all();
    }

    public function start(int $year, int $month, array $owners = []): array
    {
        if ($this->table('close_runs')->where(['year' => $year, 'month' => $month])->exists()) {
            throw new DomainException('A close checklist already exists for this month.', 'run-exists');
        }
        $end = Carbon::create($year, $month)->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($year, $month, $owners, $end) {
            $run = DB::table('close_runs')->insertGetId($this->tenant() + ['year' => $year, 'month' => $month, 'started_by' => $this->actorId(), ...$this->stamp()]);
            foreach ($this->templates() as $t) {
                if (! $t['active']) {
                    continue;
                }
                DB::table('close_tasks')->insert($this->tenant() + ['close_run_id' => $run, 'template_id' => $t['id'], 'position' => $t['position'], 'title' => $t['title'],
                    'owner_id' => $owners[$t['id']] ?? null, 'owner_role' => $t['owner_role'], 'due_date' => $end->copy()->addDays($t['due_offset_days'])->toDateString(),
                    'required' => $t['required'], 'auto_check' => $t['auto_check'], ...$this->stamp()]);
            }
            $this->audit->record('close_started', 'close_run', $run, ['year' => $year, 'month' => $month]);

            return $this->show($year, $month);
        });
    }

    public function show(int $year, int $month): array
    {
        $run = $this->table('close_runs')->where(['year' => $year, 'month' => $month])->first() ?? throw new DomainException('No close checklist for this month.', 'not-found');
        $today = Carbon::parse(DB::table('hotels')->where('id', $this->hotelId())->value('business_date'));
        $tasks = $this->table('close_tasks')->leftJoin('users as o', 'o.id', '=', 'close_tasks.owner_id')->leftJoin('users as s', 's.id', '=', 'close_tasks.signed_by')
            ->where('close_run_id', $run->id)->orderBy('position')->orderBy('close_tasks.id')->get(['close_tasks.*', 'o.name as owner_name', 's.name as signer_name'])
            ->map(fn ($t) => (array) $t + ['check_result' => $t->check_result ? json_decode($t->check_result, true) : null, 'overdue' => $t->status === 'pending' && Carbon::parse($t->due_date)->lt($today)])->all();

        return $this->summary($run) + ['tasks' => $tasks];
    }

    public function assign(int $taskId, ?int $ownerId): array
    {
        $t = $this->task($taskId);
        $this->assertOpen($t);
        $this->table('close_tasks')->where('id', $taskId)->update(['owner_id' => $ownerId, 'updated_at' => now()]);
        $this->audit->record('close_task_assigned', 'close_task', $taskId, ['owner' => $ownerId]);

        return (array) DB::table('close_tasks')->find($taskId);
    }

    public function check(int $taskId): array
    {
        $t = $this->task($taskId);
        if (! $t->auto_check) {
            throw new DomainException('This task has no automatic check.', 'no-check');
        }
        $run = DB::table('close_runs')->find($t->close_run_id);
        $result = $this->evaluate($t->auto_check, $run->year, $run->month);
        $this->table('close_tasks')->where('id', $taskId)->update(['check_result' => json_encode($result + ['at' => now()->toIso8601String()]), 'updated_at' => now()]);

        return $result;
    }

    public function sign(int $taskId, User $actor, ?string $note = null, ?string $override = null): array
    {
        $t = $this->task($taskId);
        $this->assertOpen($t);
        if ($t->status !== 'pending') {
            throw new DomainException('This task is already signed off.', 'already-signed');
        }
        $roles = $actor->roles()->pluck('name')->all();
        $mayManage = $actor->can('accounting.close_manage');
        $isOwner = $t->owner_id ? $t->owner_id === $actor->id : ($t->owner_role && in_array($t->owner_role, $roles, true));
        if (! $isOwner && ! $mayManage) {
            throw new DomainException('Only the task owner (or a close manager) can sign off.', 'not-owner', 403);
        }
        $check = null;
        if ($t->auto_check) {
            $run = DB::table('close_runs')->find($t->close_run_id);
            $check = $this->evaluate($t->auto_check, $run->year, $run->month);
            if (! $check['ok'] && ! trim((string) $override)) {
                throw new DomainException('The automatic check fails: '.$check['detail'].' Fix it, or sign with an override reason.', 'check-failed');
            }
        }

        return DB::transaction(function () use ($t, $actor, $note, $override, $check) {
            $this->table('close_tasks')->where('id', $t->id)->update(['status' => 'done', 'signed_by' => $actor->id, 'signed_at' => now(), 'note' => $note,
                'check_result' => $check ? json_encode($check + ['override' => $override, 'at' => now()->toIso8601String()]) : null, 'updated_at' => now()]);
            $this->audit->record('close_task_signed', 'close_task', $t->id, ['title' => $t->title, 'check_ok' => $check['ok'] ?? null, 'override' => $override, 'note' => $note]);
            $run = DB::table('close_runs')->find($t->close_run_id);
            $remaining = $this->table('close_tasks')->where('close_run_id', $run->id)->where('required', true)->where('status', 'pending')->count();
            $lock = null;
            if ($remaining === 0) {
                $lock = $this->complete($run, $actor);
            }

            return $this->show($run->year, $run->month) + ['lock_request' => $lock];
        });
    }

    // ---- internals ----

    private function complete(object $run, User $actor): ?array
    {
        $lock = null;
        try {
            $lock = $this->locking->request($run->year, $run->month, 'close', 'Month-end close checklist complete');
        } catch (DomainException) {
            // already closed or a request is already pending: the checklist still completes
        }
        $this->table('close_runs')->where('id', $run->id)->update(['status' => 'complete', 'completed_by' => $actor->id, 'completed_at' => now(), 'lock_request_id' => $lock['id'] ?? null, 'updated_at' => now()]);
        $this->audit->record('close_completed', 'close_run', $run->id, ['year' => $run->year, 'month' => $run->month, 'lock_request' => $lock['id'] ?? null]);

        return $lock;
    }

    private function assertOpen(object $t): void
    {
        if (DB::table('close_runs')->where('id', $t->close_run_id)->value('status') === 'complete') {
            throw new DomainException('This close is complete and locked for changes.', 'run-complete');
        }
    }

    private function task(int $id): object
    {
        return $this->table('close_tasks')->where('id', $id)->first() ?? throw new DomainException('Task not found.', 'not-found');
    }

    private function summary(object $r): array
    {
        $c = DB::table('close_tasks')->where('close_run_id', $r->id)->selectRaw("count(*) as total, count(*) filter (where status = 'done') as done, count(*) filter (where required and status = 'pending') as open_required")->first();

        return (array) $r + ['progress' => ['total' => (int) $c->total, 'done' => (int) $c->done, 'open_required' => (int) $c->open_required]];
    }

    /** @return array{ok: bool, detail: string} */
    private function evaluate(string $check, int $year, int $month): array
    {
        $h = $this->hotelId();
        $end = Carbon::create($year, $month)->endOfMonth()->toDateString();
        $start = Carbon::create($year, $month)->startOfMonth()->toDateString();
        $res = fn (bool $ok, string $detail) => ['ok' => $ok, 'detail' => $detail];

        return match ($check) {
            'trial_balance' => (function () use ($end, $res) {
                $tb = $this->ledger->balances(null, $end);
                $d = array_sum(array_column($tb, 'debit'));
                $c = array_sum(array_column($tb, 'credit'));

                return $res($d === $c, "debit $d, credit $c");
            })(),
            'bank_reconciled' => (function () use ($h, $end, $res) {
                $n = DB::table('bank_statement_lines as s')->join('bank_accounts as b', 'b.id', '=', 's.bank_account_id')->where('b.hotel_id', $h)->whereNull('s.journal_line_id')->where('s.date', '<=', $end)->count();

                return $res($n === 0, "$n unmatched statement line(s)");
            })(),
            'ap_pending' => (function () use ($h, $end, $res) {
                $n = DB::table('supplier_invoices')->where('hotel_id', $h)->where('status', 'pending')->where('date', '<=', $end)->count();

                return $res($n === 0, "$n supplier invoice(s) pending");
            })(),
            'depreciation' => (function () use ($h, $year, $month, $res) {
                if (! DB::table('fixed_assets')->where('hotel_id', $h)->exists()) {
                    return $res(true, 'no fixed assets');
                }
                $ok = DB::table('depreciation_runs')->where(['hotel_id' => $h, 'year' => $year, 'month' => $month])->exists();

                return $res($ok, $ok ? 'depreciation run posted' : 'no depreciation run for the month');
            })(),
            'vat_filed' => (function () use ($h, $start, $end, $res) {
                $ok = DB::table('vat_returns')->where('hotel_id', $h)->where('status', 'filed')->where('period_from', '<=', $start)->where('period_to', '>=', $end)->exists();

                return $res($ok, $ok ? 'VAT return filed' : 'no filed VAT return covers the month');
            })(),
            'payroll_posted' => (function () use ($h, $year, $month, $res) {
                if (! DB::table('employees')->where('hotel_id', $h)->exists()) {
                    return $res(true, 'no employees');
                }
                $ok = DB::table('payroll_runs')->where(['hotel_id' => $h, 'period' => sprintf('%04d-%02d', $year, $month)])->whereIn('status', ['approved', 'paid'])->exists();

                return $res($ok, $ok ? 'payroll approved' : 'payroll not approved for the month');
            })(),
            'suspense_clear' => (function () use ($end, $res) {
                $b = $this->ledger->balances(null, $end, [C::role('suspense')])[C::role('suspense')]['balance'] ?? 0;

                return $res($b === 0, "suspense balance $b");
            })(),
            'recurring_done' => (function () use ($h, $end, $res) {
                $n = DB::table('recurring_journals')->where('hotel_id', $h)->where('active', true)->where('next_date', '<=', $end)->count();

                return $res($n === 0, "$n recurring journal(s) due and not posted");
            })(),
            default => $res(false, 'unknown check'),
        };
    }
}

<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Account;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Prepayments, deferred income, accruals. A schedule generates dated items; `run()` posts the due ones
 * (one balanced entry per item, idempotent through source_event).
 *
 *  prepaid_expense  : Dr expense / Cr 476 (charges constatees d'avance), spread monthly over N months
 *  deferred_income  : Dr 477 (produits constates d'avance) / Cr revenue, spread monthly
 *  accrued_expense  : Dr expense / Cr 408/4486 (charges a payer) at period end, auto-reversed next month
 *  accrued_income   : Dr 418 (produits a recevoir) / Cr revenue at period end, auto-reversed next month
 *
 * The debit/credit accounts are given by the caller (defaults below); spreading assumes the original
 * invoice/payment was booked to the balance-sheet account (476/477) by the normal flow.
 */
class Deferrals
{
    public const KINDS = [
        'prepaid_expense' => ['credit' => '476000', 'name' => 'Charges constatées d\'avance', 'type' => 'asset'],
        'deferred_income' => ['debit' => '477000', 'name' => 'Produits constatés d\'avance', 'type' => 'liability'],
        'accrued_expense' => ['credit' => '408100'],
        'accrued_income' => ['debit' => '418000', 'name' => 'Clients - produits à recevoir', 'type' => 'asset'],
    ];

    public function __construct(private Ledger $ledger, private TenantContext $ctx, private AuditTrail $audit) {}

    public function list(): array
    {
        return DB::table('deferral_schedules')->where('hotel_id', $this->ctx->requireHotelId())->orderByDesc('id')->get()->map(function ($s) {
            $items = DB::table('deferral_items')->where('schedule_id', $s->id);

            return (array) $s + ['posted' => (clone $items)->whereNotNull('journal_entry_id')->count(), 'pending' => (clone $items)->whereNull('journal_entry_id')->count()];
        })->all();
    }

    public function items(int $scheduleId): array
    {
        $this->find($scheduleId);

        return DB::table('deferral_items')->where('schedule_id', $scheduleId)->orderBy('period_date')->orderBy('id')->get()->all();
    }

    public function create(array $d): array
    {
        $kind = $d['kind'];
        if (! isset(self::KINDS[$kind])) {
            throw new DomainException('Unknown schedule kind.', 'invalid-kind');
        }
        $h = $this->ctx->requireHotelId();
        $debit = $d['debit_account'] ?? self::KINDS[$kind]['debit'] ?? null;
        $credit = $d['credit_account'] ?? self::KINDS[$kind]['credit'] ?? null;
        if (! $debit || ! $credit) {
            throw new DomainException('Both accounts are required.', 'invalid-account');
        }
        foreach ([$debit, $credit] as $code) {
            $this->ensureAccount($code);
        }
        $total = (int) $d['total'];
        $months = in_array($kind, ['accrued_expense', 'accrued_income'], true) ? 1 : max(1, (int) ($d['months'] ?? 1));
        if ($total <= 0) {
            throw new DomainException('The amount must be positive.', 'invalid-amount');
        }
        $start = Carbon::parse($d['start_date']);

        return DB::transaction(function () use ($d, $kind, $debit, $credit, $total, $months, $start, $h) {
            $id = DB::table('deferral_schedules')->insertGetId([
                'group_id' => $this->ctx->groupId(), 'hotel_id' => $h, 'kind' => $kind, 'description' => $d['description'], 'debit_account' => $debit, 'credit_account' => $credit,
                'total' => $total, 'start_date' => $start->toDateString(), 'months' => $months, 'auto_reverse' => in_array($kind, ['accrued_expense', 'accrued_income'], true),
                'department' => $d['department'] ?? null, 'outlet' => $d['outlet'] ?? null, 'project' => $d['project'] ?? null, 'cost_centre' => $d['cost_centre'] ?? null,
                'status' => 'active', 'created_by' => $this->ctx->actor()?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $items = [];
            if ($months === 1 && in_array($kind, ['accrued_expense', 'accrued_income'], true)) {
                $end = $start->copy()->endOfMonth();
                $items[] = ['entry_type' => 'accrual', 'period_date' => $end->toDateString(), 'amount' => $total];
                $items[] = ['entry_type' => 'reversal', 'period_date' => $end->copy()->addDay()->toDateString(), 'amount' => $total];
            } else {
                $base = intdiv($total, $months);
                for ($i = 0; $i < $months; $i++) {
                    $items[] = ['entry_type' => 'release', 'period_date' => $start->copy()->addMonthsNoOverflow($i)->endOfMonth()->toDateString(), 'amount' => $i === $months - 1 ? $total - $base * ($months - 1) : $base];
                }
            }
            DB::table('deferral_items')->insert(array_map(fn ($i) => $i + ['schedule_id' => $id, 'hotel_id' => $h], $items));
            $this->audit->record('schedule_created', 'deferral', $id, ['kind' => $kind, 'total' => $total, 'months' => $months]);

            return ['id' => $id] + (array) DB::table('deferral_schedules')->find($id) + ['items' => $this->items($id)];
        });
    }

    public function cancel(int $id): array
    {
        $this->find($id);
        DB::table('deferral_schedules')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->audit->record('schedule_cancelled', 'deferral', $id);

        return (array) DB::table('deferral_schedules')->find($id);
    }

    /** Post every unposted item due on or before $asOf (for this hotel). Returns [posted, failed]. */
    public function run(string|Carbon $asOf): array
    {
        $asOf = Carbon::parse($asOf)->toDateString();
        $posted = $failed = 0;
        $items = DB::table('deferral_items as i')->join('deferral_schedules as s', 's.id', '=', 'i.schedule_id')
            ->where('i.hotel_id', $this->ctx->requireHotelId())->where('s.status', 'active')->whereNull('i.journal_entry_id')->where('i.period_date', '<=', $asOf)
            ->orderBy('i.period_date')->orderBy('i.id')->get(['i.id as item_id', 'i.entry_type', 'i.period_date', 'i.amount', 's.*']);
        foreach ($items as $it) {
            $rev = $it->entry_type === 'reversal';
            $dims = ['department' => $it->department, 'outlet' => $it->outlet, 'project' => $it->project, 'cost_centre' => $it->cost_centre];
            // accrued/deferred kinds: the P&L side carries the dimensions
            $pl = in_array($it->debit_account[0], ['6', '7'], true) ? 'debit' : 'credit';
            $d = ['account' => $rev ? $it->credit_account : $it->debit_account, 'debit' => $it->amount, 'credit' => 0, 'description' => $it->description];
            $c = ['account' => $rev ? $it->debit_account : $it->credit_account, 'debit' => 0, 'credit' => $it->amount, 'description' => $it->description];
            $d += $pl === 'debit' ? $dims : [];
            $c += $pl === 'credit' ? $dims : [];
            try {
                $entry = DB::transaction(function () use ($it, $d, $c) {
                    $e = $this->ledger->post('OD', $it->period_date, ucfirst(str_replace('_', ' ', $it->entry_type))." - {$it->description}", [$d, $c],
                        ['source_type' => 'deferral', 'source_id' => $it->item_id, 'source_event' => "deferral:{$it->item_id}"]);
                    DB::table('deferral_items')->where('id', $it->item_id)->update(['journal_entry_id' => $e?->id, 'posted_at' => now(), 'error' => null]);

                    return $e;
                });
                $posted++;
                if ($entry) {
                    $this->audit->record('schedule_item_posted', 'deferral', $it->id, ['item' => $it->item_id, 'entry' => $entry->number]);
                }
            } catch (DomainException $e) {
                DB::table('deferral_items')->where('id', $it->item_id)->update(['error' => mb_substr($e->getMessage(), 0, 250)]);
                $failed++;
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    private function ensureAccount(string $code): void
    {
        if (Account::where('code', $code)->exists()) {
            return;
        }
        $meta = collect(self::KINDS)->first(fn ($k) => ($k['credit'] ?? $k['debit'] ?? null) === $code && isset($k['name']));
        if (! $meta) {
            throw new DomainException("Unknown account $code.", 'invalid-account');
        }
        Account::create(['code' => $code, 'name' => $meta['name'], 'class' => (int) $code[0], 'type' => $meta['type'], 'parent_code' => null, 'postable' => true, 'active' => true]);
    }

    private function find(int $id): object
    {
        return DB::table('deferral_schedules')->where(['id' => $id, 'hotel_id' => $this->ctx->requireHotelId()])->first() ?? throw new DomainException('Schedule not found.', 'not-found');
    }
}

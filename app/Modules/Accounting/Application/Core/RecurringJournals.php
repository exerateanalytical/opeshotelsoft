<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Accounting\Application\Ledger;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Recurring journal templates (rent, insurance, standing allocations): fixed balanced lines, posted on schedule. */
class RecurringJournals
{
    public function __construct(private Ledger $ledger, private TenantContext $ctx, private AuditTrail $audit) {}

    public function list(): array
    {
        return DB::table('recurring_journals')->where('hotel_id', $this->ctx->requireHotelId())->orderBy('name')->get()
            ->map(fn ($r) => (array) $r + ['lines' => json_decode($r->lines, true)])->all();
    }

    public function save(array $d, ?int $id = null): array
    {
        $h = $this->ctx->requireHotelId();
        $debit = $credit = 0;
        foreach ($d['lines'] as $l) {
            $debit += (int) ($l['debit'] ?? 0);
            $credit += (int) ($l['credit'] ?? 0);
        }
        if ($debit !== $credit || $debit <= 0) {
            throw new DomainException('A recurring template must balance.', 'unbalanced-entry');
        }
        $row = ['journal' => $d['journal'], 'name' => $d['name'], 'frequency' => $d['frequency'], 'next_date' => $d['next_date'], 'end_date' => $d['end_date'] ?? null,
            'lines' => json_encode($d['lines']), 'active' => $d['active'] ?? true, 'updated_at' => now()];
        if ($id) {
            DB::table('recurring_journals')->where(['id' => $id, 'hotel_id' => $h])->update($row);
        } else {
            $id = DB::table('recurring_journals')->insertGetId($row + ['group_id' => $this->ctx->groupId(), 'hotel_id' => $h, 'created_by' => $this->ctx->actor()?->id, 'created_at' => now()]);
        }
        $this->audit->record('recurring_saved', 'recurring', $id, ['name' => $d['name'], 'frequency' => $d['frequency'], 'total' => $debit]);

        return (array) DB::table('recurring_journals')->find($id);
    }

    /** Post every occurrence due on or before $asOf (catch-up limited to 36 occurrences per template). */
    public function run(string|Carbon $asOf): array
    {
        $asOf = Carbon::parse($asOf);
        $posted = $failed = 0;
        $templates = DB::table('recurring_journals')->where('hotel_id', $this->ctx->requireHotelId())->where('active', true)->where('next_date', '<=', $asOf->toDateString())->get();
        foreach ($templates as $t) {
            $next = Carbon::parse($t->next_date);
            for ($i = 0; $i < 36 && $next->lte($asOf) && (! $t->end_date || $next->lte(Carbon::parse($t->end_date))); $i++) {
                try {
                    DB::transaction(fn () => $this->ledger->post($t->journal, $next, $t->name, json_decode($t->lines, true), [
                        'source_type' => 'recurring', 'source_id' => $t->id, 'source_event' => "recurring:{$t->id}:{$next->toDateString()}",
                    ]));
                    $posted++;
                    $this->audit->record('recurring_posted', 'recurring', $t->id, ['date' => $next->toDateString()]);
                } catch (DomainException $e) {
                    $failed++;
                    $this->audit->record('recurring_failed', 'recurring', $t->id, ['date' => $next->toDateString(), 'error' => $e->getMessage()]);
                    break; // keep next_date on the failed occurrence so the next run retries it
                }
                $next = match ($t->frequency) {
                    'quarterly' => $next->copy()->addMonthsNoOverflow(3),
                    'yearly' => $next->copy()->addYearNoOverflow(),
                    default => $next->copy()->addMonthNoOverflow(),
                };
                DB::table('recurring_journals')->where('id', $t->id)->update(['next_date' => $next->toDateString(), 'last_run_at' => now()]);
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }
}

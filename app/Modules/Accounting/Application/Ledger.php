<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way to write to the general ledger. Guarantees:
 *  - every entry balances (sum debit = sum credit, > 0)
 *  - accounts exist, are active and postable
 *  - the fiscal period is open
 *  - one entry per source event (idempotent replays)
 */
class Ledger
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    /**
     * Meta 'closing' => true is reserved for the year-end closing entry (posts into closed months of an unlocked year).
     * Line keys currency / fx_amount / fx_rate record the foreign-currency side of a line.
     *
     * Lines: [account, signedAmount, meta?] where positive = debit, negative = credit,
     * or ['account' =>, 'debit' =>, 'credit' =>, 'partner_type' =>, 'partner_id' =>, 'department' =>, 'description' =>].
     */
    public function post(string $journal, string|Carbon $date, string $description, array $lines, array $meta = []): ?JournalEntry
    {
        $hotelId = $this->ctx->requireHotelId();
        $date = Carbon::parse($date);

        if (! empty($meta['source_event']) && JournalEntry::where('source_event', $meta['source_event'])->exists()) {
            return null;
        }

        $normalized = [];
        foreach ($lines as $l) {
            if (array_is_list($l)) {
                [$account, $signed] = $l;
                $l = ($l[2] ?? []) + ['account' => $account, 'debit' => max(0, $signed), 'credit' => max(0, -$signed)];
            }
            $l['debit'] = (int) ($l['debit'] ?? 0);
            $l['credit'] = (int) ($l['credit'] ?? 0);
            if ($l['debit'] < 0 || $l['credit'] < 0) {
                [$l['debit'], $l['credit']] = [max(0, $l['debit'], -$l['credit']), max(0, $l['credit'], -$l['debit'])];
            }
            if ($l['debit'] === 0 && $l['credit'] === 0) {
                continue;
            }
            $normalized[] = $l;
        }

        $debit = array_sum(array_column($normalized, 'debit'));
        $credit = array_sum(array_column($normalized, 'credit'));
        if ($debit !== $credit) {
            throw new DomainException("Unbalanced journal entry: debit $debit ≠ credit $credit ($description).", 'unbalanced-entry');
        }
        if ($debit === 0) {
            return null;
        }

        $codes = array_unique(array_column($normalized, 'account'));
        $accounts = Account::whereIn('code', $codes)->where('active', true)->where('postable', true)->pluck('code')->all();
        if ($missing = array_diff($codes, $accounts)) {
            throw new DomainException('Unknown or non-postable account(s): '.implode(', ', $missing), 'invalid-account');
        }

        if (empty($meta['closing'])) {
            $this->assertPeriodOpen($hotelId, $date);
        } else {
            $this->assertYearNotLocked($hotelId, $date);
        }

        return DB::transaction(function () use ($hotelId, $journal, $date, $description, $normalized, $meta, $debit) {
            $entry = JournalEntry::create([
                'journal_code' => $journal,
                'number' => NumberSequence::next($hotelId, 'je-'.$journal, $journal),
                'date' => $date->toDateString(),
                'description' => mb_substr($description, 0, 250),
                'source_type' => $meta['source_type'] ?? null,
                'source_id' => isset($meta['source_id']) ? (string) $meta['source_id'] : null,
                'source_event' => $meta['source_event'] ?? null,
                'reverses_entry_id' => $meta['reverses_entry_id'] ?? null,
                'total' => $debit,
                'created_by' => $this->ctx->actor()?->id,
                'approved_by' => $meta['approved_by'] ?? null,
            ]);
            foreach ($normalized as $l) {
                $entry->lines()->create([
                    'hotel_id' => $hotelId,
                    'account_code' => $l['account'],
                    'debit' => $l['debit'],
                    'credit' => $l['credit'],
                    'partner_type' => $l['partner_type'] ?? null,
                    'partner_id' => $l['partner_id'] ?? null,
                    'department' => $l['department'] ?? null,
                    'description' => $l['description'] ?? null,
                    'date' => $date->toDateString(),
                    'currency' => isset($l['currency']) ? strtoupper($l['currency']) : null,
                    'fx_amount' => isset($l['fx_amount']) ? abs((float) $l['fx_amount']) : null,
                    'fx_rate' => $l['fx_rate'] ?? null,
                ]);
            }
            $this->bus->record('accounting.entry_posted', ['id' => $entry->id, 'number' => $entry->number, 'journal' => $journal, 'total' => $debit, 'date' => $date->toDateString()], 'journal_entry', $entry->id);

            return $entry;
        });
    }

    public function reverse(JournalEntry $entry, string $reason, ?string $date = null): JournalEntry
    {
        if ($entry->reversed || $entry->reverses_entry_id) {
            throw new DomainException('This entry is already reversed or is itself a reversal.');
        }
        $lines = $entry->lines->map(fn ($l) => [
            'account' => $l->account_code, 'debit' => $l->credit, 'credit' => $l->debit,
            'partner_type' => $l->partner_type, 'partner_id' => $l->partner_id, 'department' => $l->department,
        ])->all();

        return DB::transaction(function () use ($entry, $reason, $date, $lines) {
            $rev = $this->post($entry->journal_code, $date ?? $entry->date, "Extourne {$entry->number}: $reason", $lines, ['reverses_entry_id' => $entry->id, 'source_type' => 'reversal', 'source_id' => $entry->id]);
            $entry->forceFill(['reversed' => true])->save();

            return $rev;
        });
    }

    public function assertPeriodOpen(int $hotelId, Carbon $date): void
    {
        $closed = DB::table('fiscal_periods')->where(['hotel_id' => $hotelId, 'year' => $date->year, 'month' => $date->month, 'status' => 'closed'])->exists();
        if ($closed) {
            throw new DomainException("The accounting period {$date->format('m/Y')} is closed.", 'period-closed');
        }
        $this->assertYearNotLocked($hotelId, $date);
    }

    /** A closed fiscal year is final: nothing may be posted in it, even if a month is reopened. */
    public function assertYearNotLocked(int $hotelId, Carbon $date): void
    {
        if (DB::table('fiscal_years')->where(['hotel_id' => $hotelId, 'year' => $date->year, 'status' => 'locked'])->exists()) {
            throw new DomainException("The fiscal year {$date->year} is closed and locked.", 'year-locked');
        }
    }

    /** Balance (debit - credit) per account up to a date. */
    public function balances(?string $from = null, ?string $to = null, ?array $accounts = null): array
    {
        return DB::table('journal_lines')->where('hotel_id', $this->ctx->requireHotelId())
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->when($accounts, fn ($q) => $q->whereIn('account_code', $accounts))
            ->selectRaw('account_code, sum(debit) as debit, sum(credit) as credit')
            ->groupBy('account_code')->orderBy('account_code')->get()
            ->mapWithKeys(fn ($r) => [$r->account_code => ['debit' => (int) $r->debit, 'credit' => (int) $r->credit, 'balance' => (int) $r->debit - (int) $r->credit]])
            ->all();
    }
}

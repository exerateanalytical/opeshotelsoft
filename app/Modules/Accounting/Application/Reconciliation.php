<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\BankStatementImport;
use App\Modules\Accounting\Domain\BankStatementLine;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\JournalLine;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bank and Mobile Money reconciliation: CSV statement import with a
 * configurable column mapping, auto/manual matching to ledger lines on the
 * treasury account, posting of differences (bank charges etc.), and the
 * reconciliation report.
 */
class Reconciliation
{
    public const DATE_TOLERANCE_DAYS = 3;

    public function __construct(private Ledger $ledger, private EventBus $bus, private TenantContext $ctx) {}

    /**
     * @param  array  $mapping  column => header name or 0-based index; keys: date, amount (or debit + credit), reference, balance, description.
     */
    public function import(BankAccount $bank, string $csv, array $mapping, array $options = []): BankStatementImport
    {
        $delimiter = $options['delimiter'] ?? $this->guessDelimiter($csv);
        $hasHeader = $options['has_header'] ?? true;
        $dateFormat = $options['date_format'] ?? null;

        $rows = array_values(array_filter(
            array_map(fn ($l) => str_getcsv($l, $delimiter, '"', ''), preg_split('/\r\n|\n|\r/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv)))),
            fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0,
        ));
        if (! $rows) {
            throw new DomainException('The statement file is empty.', 'empty-statement');
        }
        $headers = $hasHeader ? array_map(fn ($h) => mb_strtolower(trim($h)), array_shift($rows)) : [];
        $col = function (string $key) use ($mapping, $headers): ?int {
            $m = $mapping[$key] ?? null;
            if ($m === null || $m === '') {
                return null;
            }
            if (is_int($m) || ctype_digit((string) $m)) {
                return (int) $m;
            }
            $i = array_search(mb_strtolower(trim($m)), $headers, true);
            if ($i === false) {
                throw new DomainException("Column \"$m\" not found in the statement header.", 'bad-mapping');
            }

            return $i;
        };
        $cDate = $col('date') ?? throw new DomainException('Map the date column.', 'bad-mapping');
        $cAmount = $col('amount');
        $cDebit = $col('debit');
        $cCredit = $col('credit');
        if ($cAmount === null && $cDebit === null && $cCredit === null) {
            throw new DomainException('Map the amount column (or debit and credit columns).', 'bad-mapping');
        }
        $cRef = $col('reference');
        $cBal = $col('balance');
        $cDesc = $col('description');

        return DB::transaction(function () use ($bank, $rows, $mapping, $options, $cDate, $cAmount, $cDebit, $cCredit, $cRef, $cBal, $cDesc, $dateFormat, $delimiter, $hasHeader) {
            $import = BankStatementImport::create([
                'bank_account_id' => $bank->id, 'filename' => $options['filename'] ?? null,
                'mapping' => $mapping + ['delimiter' => $delimiter, 'has_header' => $hasHeader, 'date_format' => $dateFormat],
                'imported_by' => $this->ctx->actor()?->id,
            ]);
            $created = $skipped = 0;
            $first = $last = null;
            foreach ($rows as $n => $r) {
                try {
                    $date = $dateFormat ? Carbon::createFromFormat($dateFormat, trim($r[$cDate] ?? '')) : $this->parseDate(trim($r[$cDate] ?? ''));
                } catch (\Throwable) {
                    throw new DomainException('Row '.($n + 1).': unreadable date "'.($r[$cDate] ?? '').'".', 'bad-row');
                }
                $amount = $cAmount !== null
                    ? self::money($r[$cAmount] ?? '')
                    : self::money($r[$cCredit] ?? '') - abs(self::money($r[$cDebit] ?? ''));
                $reference = $cRef !== null ? trim($r[$cRef] ?? '') ?: null : null;
                $balance = $cBal !== null && trim($r[$cBal] ?? '') !== '' ? self::money($r[$cBal]) : null;
                $description = $cDesc !== null ? trim($r[$cDesc] ?? '') : '';

                $dupe = BankStatementLine::where('bank_account_id', $bank->id)->whereDate('date', $date->toDateString())
                    ->where('amount', $amount)->where('reference', $reference)->where('balance', $balance)->exists();
                if ($dupe) {
                    $skipped++;

                    continue;
                }
                $line = BankStatementLine::create([
                    'bank_account_id' => $bank->id, 'bank_statement_import_id' => $import->id, 'date' => $date->toDateString(),
                    'description' => mb_substr($description ?: ($reference ?? 'Statement line'), 0, 250),
                    'amount' => $amount, 'reference' => $reference, 'balance' => $balance,
                ]);
                $first ??= $line;
                $last = $line;
                $created++;
            }
            $import->update([
                'rows' => $created, 'skipped' => $skipped,
                'from' => $first?->date, 'to' => $last?->date,
                'opening_balance' => $first && $first->balance !== null ? $first->balance - $first->amount : null,
                'closing_balance' => $last?->balance,
            ]);
            $this->bus->record('accounting.statement_imported', ['id' => $import->id, 'bank_account_id' => $bank->id, 'rows' => $created], 'bank_account', $bank->id);

            return $import;
        });
    }

    /** Match unmatched statement lines to ledger lines: same signed amount, date ±3 days, reference preferred. */
    public function autoMatch(BankAccount $bank): array
    {
        $matched = 0;
        $lines = BankStatementLine::where('bank_account_id', $bank->id)->whereNull('journal_line_id')->orderBy('date')->orderBy('id')->get();
        foreach ($lines as $s) {
            $candidates = $this->openLedgerLines($bank)
                ->whereBetween('journal_lines.date', [$s->date->copy()->subDays(self::DATE_TOLERANCE_DAYS)->toDateString(), $s->date->copy()->addDays(self::DATE_TOLERANCE_DAYS)->toDateString()])
                ->whereRaw('(journal_lines.debit - journal_lines.credit) = ?', [$s->amount])
                ->get();
            if ($candidates->isEmpty()) {
                continue;
            }
            $ref = $s->reference ? mb_strtolower($s->reference) : null;
            $best = $candidates->sortBy(function ($c) use ($s, $ref) {
                $refHit = $ref && (str_contains(mb_strtolower($c->number.' '.$c->entry_description.' '.$c->description), $ref)) ? 0 : 1;

                return [$refHit, abs(Carbon::parse($c->date)->diffInDays($s->date))];
            })->first();
            $this->link($s, (int) $best->id, 'auto');
            $matched++;
        }

        return ['matched' => $matched, 'unmatched' => BankStatementLine::where('bank_account_id', $bank->id)->whereNull('journal_line_id')->count()];
    }

    public function match(BankStatementLine $s, int $journalLineId): BankStatementLine
    {
        if ($s->journal_line_id) {
            throw new DomainException('This statement line is already matched.', 'already-matched');
        }
        $bank = BankAccount::findOrFail($s->bank_account_id);
        $jl = $this->openLedgerLines($bank)->where('journal_lines.id', $journalLineId)->first();
        if (! $jl) {
            throw new DomainException('The ledger line is not an open line of this treasury account.', 'invalid-line');
        }
        if ((int) $jl->debit - (int) $jl->credit !== (int) $s->amount) {
            throw new DomainException('Amounts differ; post the difference instead.', 'amount-mismatch');
        }

        return $this->link($s, $journalLineId, 'manual');
    }

    public function unmatch(BankStatementLine $s): BankStatementLine
    {
        if (! $s->journal_line_id) {
            return $s;
        }
        DB::transaction(function () use ($s) {
            JournalLine::whereKey($s->journal_line_id)->update(['reconciliation_ref' => null]);
            $s->update(['journal_line_id' => null, 'reconciled_at' => null, 'match_kind' => null]);
        });
        $this->bus->record('accounting.statement_line_unmatched', ['id' => $s->id], 'bank_account', $s->bank_account_id);

        return $s->fresh();
    }

    /** Post a statement line that has no ledger counterpart (bank charges, interest, …) and match it. */
    public function postDifference(BankStatementLine $s, ?string $account, ?string $description): BankStatementLine
    {
        if ($s->journal_line_id) {
            throw new DomainException('This statement line is already matched.', 'already-matched');
        }
        $bank = BankAccount::findOrFail($s->bank_account_id);
        $account ??= $s->amount < 0 ? C::role('bank_charges') : C::role('other_income');
        $journal = $bank->kind === 'mobile_money' ? 'MM' : 'BQ';

        return DB::transaction(function () use ($s, $bank, $account, $description, $journal) {
            $entry = $this->ledger->post($journal, $s->date, $description ?: ('Relevé: '.$s->description), [
                [$bank->gl_account, (int) $s->amount, ['description' => $s->reference]],
                [$account, -(int) $s->amount],
            ], ['source_type' => 'bank_statement_line', 'source_id' => $s->id]);
            $line = $entry->lines()->where('account_code', $bank->gl_account)->firstOrFail();

            return $this->link($s, $line->id, 'posted');
        });
    }

    public function report(BankAccount $bank, string $from, string $to): array
    {
        $stmt = BankStatementLine::where('bank_account_id', $bank->id);
        $before = (clone $stmt)->whereDate('date', '<', $from)->orderByDesc('date')->orderByDesc('id')->first();
        $firstIn = (clone $stmt)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date')->orderBy('id')->first();
        $lastIn = (clone $stmt)->whereDate('date', '<=', $to)->orderByDesc('date')->orderByDesc('id')->first();
        $opening = $before?->balance ?? ($firstIn && $firstIn->balance !== null ? $firstIn->balance - $firstIn->amount : null);
        $movements = (int) (clone $stmt)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('amount');
        $closing = $lastIn?->balance ?? ($opening !== null ? $opening + $movements : null);

        $book = $this->ledger->balances(null, $to, [$bank->gl_account])[$bank->gl_account]['balance'] ?? 0;
        $bookOpening = $this->ledger->balances(null, Carbon::parse($from)->subDay()->toDateString(), [$bank->gl_account])[$bank->gl_account]['balance'] ?? 0;

        $unmatchedStatement = (clone $stmt)->whereNull('journal_line_id')->whereDate('date', '<=', $to)->orderBy('date')->get(['id', 'date', 'description', 'reference', 'amount']);
        $unmatchedLedger = $this->openLedgerLines($bank)->where('journal_lines.date', '<=', $to)->orderBy('journal_lines.date')->get();
        $sumStatement = (int) $unmatchedStatement->sum('amount');
        $sumLedger = (int) $unmatchedLedger->sum(fn ($l) => (int) $l->debit - (int) $l->credit);
        $adjustedBook = $book + $sumStatement;
        $adjustedStatement = $closing !== null ? $closing + $sumLedger : null;

        return [
            'bank_account' => $bank->only(['id', 'name', 'kind', 'gl_account', 'currency']),
            'from' => $from, 'to' => $to,
            'statement_opening_balance' => $opening, 'statement_movements' => $movements, 'statement_closing_balance' => $closing,
            'book_opening_balance' => $bookOpening, 'book_closing_balance' => $book,
            'unmatched_statement_lines' => $unmatchedStatement, 'unmatched_statement_total' => $sumStatement,
            'unmatched_ledger_lines' => $unmatchedLedger->map(fn ($l) => [
                'id' => $l->id, 'date' => $l->date, 'number' => $l->number, 'description' => $l->description ?: $l->entry_description, 'amount' => (int) $l->debit - (int) $l->credit,
            ])->values(), 'unmatched_ledger_total' => $sumLedger,
            'adjusted_book_balance' => $adjustedBook, 'adjusted_statement_balance' => $adjustedStatement,
            'difference' => $adjustedStatement !== null ? $adjustedStatement - $adjustedBook : null,
            'reconciled' => $adjustedStatement !== null && $adjustedStatement === $adjustedBook,
        ];
    }

    /** Ledger lines on the treasury account that are not yet reconciled. */
    public function openLedgerLines(BankAccount $bank)
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.hotel_id', $this->ctx->requireHotelId())
            ->where('journal_lines.account_code', $bank->gl_account)
            ->whereNull('journal_lines.reconciliation_ref')
            ->select('journal_lines.id', 'journal_lines.date', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.description', 'journal_entries.number', 'journal_entries.description as entry_description');
    }

    private function link(BankStatementLine $s, int $journalLineId, string $kind): BankStatementLine
    {
        DB::transaction(function () use ($s, $journalLineId, $kind) {
            JournalLine::whereKey($journalLineId)->update(['reconciliation_ref' => 'BSL-'.$s->id]);
            $s->update(['journal_line_id' => $journalLineId, 'reconciled_at' => now(), 'match_kind' => $kind]);
        });

        return $s->fresh();
    }

    /** "1 234 567", "-1,234.00", "1.234,50", "(500)" → integer FCFA. */
    public static function money(string|int|float|null $v): int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }
        $neg = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')')) || str_ends_with($v, '-');
        $v = preg_replace('/[^0-9.,]/', '', $v);
        if (preg_match('/[.,](\d{1,2})$/', $v, $m)) {
            $int = preg_replace('/[^0-9]/', '', substr($v, 0, -strlen($m[0])));
            $n = (float) ($int.'.'.$m[1]);
        } else {
            $n = (float) preg_replace('/[^0-9]/', '', $v);
        }

        return (int) round($neg ? -$n : $n);
    }

    private function parseDate(string $v): Carbon
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $v);
            if ($d && $d->format($f) === $v) {
                return Carbon::instance($d);
            }
        }

        return Carbon::parse($v);
    }

    private function guessDelimiter(string $csv): string
    {
        $head = strtok($csv, "\n") ?: '';

        return collect([';', ',', "\t", '|'])->sortByDesc(fn ($d) => substr_count($head, $d))->first();
    }
}

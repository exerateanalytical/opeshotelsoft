<?php

namespace App\Modules\Accounting\Payables;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Application\Reconciliation;
use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\BankStatementLine;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Support\Scoped;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Bank-feed import (CSV via the existing mapping import, MT940, CAMT.053) feeding the existing reconciliation, plus a rules
 * engine: the first active rule (by priority) matching a statement line's text / amount either posts the line to an account and partner
 * (auto_post) or records a suggestion for a clerk to accept. Posting uses the bank journal and links the line like a manual match.
 */
class BankFeed
{
    use Scoped;

    public function __construct(TenantContext $ctx, private Reconciliation $recon, private Ledger $ledger, private AuditTrail $audit)
    {
        $this->ctx = $ctx;
    }

    /** @param array $opts mapping (csv), apply_rules (bool, default true), auto_match (bool, default true) */
    public function import(BankAccount $bank, string $format, string $content, array $opts = []): array
    {
        if ($format === 'csv') {
            $import = $this->recon->import($bank, $content, $opts['mapping'] ?? throw new DomainException('Map the CSV columns.', 'bad-mapping'), ['filename' => $opts['filename'] ?? null]);
            $meta = [];
        } else {
            $parsed = StatementParser::parse($format, $content);
            $import = $this->recon->import($bank, StatementParser::toCsv($parsed['rows']), ['date' => 'date', 'amount' => 'amount', 'reference' => 'reference', 'description' => 'description'],
                ['filename' => $opts['filename'] ?? null, 'delimiter' => ';', 'date_format' => 'Y-m-d']);
            $meta = ['statement_account' => $parsed['account'], 'opening_balance' => $parsed['opening'], 'closing_balance' => $parsed['closing']];
            if ($parsed['closing'] !== null && $import->closing_balance === null) {
                $import->update(['opening_balance' => $parsed['opening'], 'closing_balance' => $parsed['closing']]);
            }
        }
        $rules = ($opts['apply_rules'] ?? true) ? $this->apply($bank) : ['posted' => 0, 'suggested' => 0];
        $matched = ($opts['auto_match'] ?? true) ? $this->recon->autoMatch($bank) : null;
        $this->audit->record('bank_feed_imported', 'bank_account', $bank->id, ['format' => $format, 'rows' => $import->rows, 'skipped' => $import->skipped] + $rules);

        return ['import' => $import->fresh()->toArray(), 'rules' => $rules, 'auto_match' => $matched] + $meta;
    }

    // ---- rules ----

    public function rules(): array
    {
        return $this->table('bank_rules')->orderBy('priority')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function saveRule(array $d, ?int $id = null): array
    {
        if (($d['match_mode'] ?? 'contains') === 'regex' && @preg_match('/'.str_replace('/', '\/', $d['pattern']).'/iu', '') === false) {
            throw new DomainException('The regular expression is invalid.', 'bad-pattern');
        }
        if (! DB::table('accounts')->where(['hotel_id' => $this->hotelId(), 'code' => $d['account_code'], 'postable' => true])->exists()) {
            throw new DomainException('Unknown or non-postable account.', 'invalid-account');
        }
        $row = array_intersect_key($d, array_flip(['name', 'priority', 'bank_account_id', 'direction', 'match_mode', 'pattern', 'amount_min', 'amount_max', 'account_code', 'partner_type', 'partner_id', 'label', 'auto_post', 'active'])) + ['updated_at' => now()];
        if ($id) {
            $this->table('bank_rules')->where('id', $id)->exists() || throw new DomainException('Rule not found.', 'not-found');
            $this->table('bank_rules')->where('id', $id)->update($row);
        } else {
            $id = DB::table('bank_rules')->insertGetId($row + $this->tenant() + ['created_at' => now()]);
        }
        $this->audit->record('bank_rule_saved', 'bank_rule', $id, $row);

        return (array) DB::table('bank_rules')->find($id);
    }

    /** First active rule that matches a line (null when none). */
    public function match(object $line, int $bankId): ?object
    {
        $hay = mb_strtolower(trim(($line->description ?? '').' '.($line->reference ?? '')));
        foreach ($this->table('bank_rules')->where('active', true)->orderBy('priority')->orderBy('id')->get() as $r) {
            if ($r->bank_account_id && $r->bank_account_id !== $bankId) {
                continue;
            }
            if (($r->direction === 'in' && $line->amount <= 0) || ($r->direction === 'out' && $line->amount >= 0)) {
                continue;
            }
            $abs = abs((int) $line->amount);
            if (($r->amount_min !== null && $abs < $r->amount_min) || ($r->amount_max !== null && $abs > $r->amount_max)) {
                continue;
            }
            $hit = $r->match_mode === 'regex'
                ? (bool) @preg_match('/'.str_replace('/', '\/', $r->pattern).'/iu', $hay)
                : str_contains($hay, mb_strtolower($r->pattern));
            if ($hit) {
                return $r;
            }
        }

        return null;
    }

    /** @return array{posted:int, suggested:int} */
    public function apply(BankAccount $bank): array
    {
        $posted = $suggested = 0;
        $lines = BankStatementLine::where('bank_account_id', $bank->id)->whereNull('journal_line_id')->orderBy('date')->orderBy('id')->get();
        $hits = DB::table('bank_rule_hits')->where('hotel_id', $this->hotelId())->pluck('status', 'bank_statement_line_id');
        foreach ($lines as $l) {
            if (isset($hits[$l->id])) {
                continue;
            }
            $rule = $this->match($l, $bank->id);
            if (! $rule) {
                continue;
            }
            if ($rule->auto_post) {
                $this->post($l, $rule, $bank);
                $posted++;
            } else {
                DB::table('bank_rule_hits')->insert(['hotel_id' => $this->hotelId(), 'bank_statement_line_id' => $l->id, 'bank_rule_id' => $rule->id, 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
                $suggested++;
            }
        }

        return ['posted' => $posted, 'suggested' => $suggested];
    }

    public function suggestions(BankAccount $bank): array
    {
        return DB::table('bank_rule_hits as h')->join('bank_statement_lines as s', 's.id', '=', 'h.bank_statement_line_id')->join('bank_rules as r', 'r.id', '=', 'h.bank_rule_id')
            ->where('h.hotel_id', $this->hotelId())->where('h.status', 'suggested')->where('s.bank_account_id', $bank->id)->whereNull('s.journal_line_id')
            ->orderBy('s.date')->get(['h.id as hit_id', 's.id as line_id', 's.date', 's.description', 's.amount', 's.reference', 'r.id as rule_id', 'r.name as rule', 'r.account_code', 'r.partner_type', 'r.partner_id'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Accept the suggestion made for a line (posts it). */
    public function accept(BankStatementLine $line): BankStatementLine
    {
        $hit = DB::table('bank_rule_hits')->where(['hotel_id' => $this->hotelId(), 'bank_statement_line_id' => $line->id, 'status' => 'suggested'])->first()
            ?? throw new DomainException('No pending suggestion for this line.', 'no-suggestion');
        $rule = DB::table('bank_rules')->find($hit->bank_rule_id);

        return $this->post($line, $rule, BankAccount::findOrFail($line->bank_account_id));
    }

    private function post(BankStatementLine $l, object $rule, BankAccount $bank): BankStatementLine
    {
        if ($l->journal_line_id) {
            throw new DomainException('This statement line is already matched.', 'already-matched');
        }

        return DB::transaction(function () use ($l, $rule, $bank) {
            $partner = $rule->partner_type ? ['partner_type' => $rule->partner_type, 'partner_id' => $rule->partner_id] : [];
            $entry = $this->ledger->post($bank->kind === 'mobile_money' ? 'MM' : 'BQ', $l->date, $rule->label ?: ('Règle banque: '.$rule->name.' - '.$l->description), [
                [$bank->gl_account, (int) $l->amount, ['description' => $l->reference]],
                [$rule->account_code, -(int) $l->amount, $partner],
            ], ['source_type' => 'bank_rule', 'source_id' => $l->id]);
            $jl = $entry->lines()->where('account_code', $bank->gl_account)->firstOrFail();
            JournalLine::whereKey($jl->id)->update(['reconciliation_ref' => 'BSL-'.$l->id]);
            $l->update(['journal_line_id' => $jl->id, 'reconciled_at' => now(), 'match_kind' => 'rule']);
            DB::table('bank_rule_hits')->updateOrInsert(['bank_statement_line_id' => $l->id], ['hotel_id' => $this->hotelId(), 'bank_rule_id' => $rule->id, 'status' => 'applied', 'updated_at' => now(), 'created_at' => now()]);
            $this->audit->record('bank_rule_applied', 'bank_statement_line', $l->id, ['rule' => $rule->id, 'account' => $rule->account_code, 'entry' => $entry->id]);

            return $l->fresh();
        });
    }
}

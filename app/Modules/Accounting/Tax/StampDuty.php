<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Support\Scoped;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Stamp duty (droit de timbre) as configurable rules (percent of a base or fixed, with minimums). record() computes the duty,
 * books it (Dr 646100 expense / Cr 447400 duty to remit) and keeps the register line. Which documents attract duty, and who bears it,
 * is for the chartered accountant to confirm; nothing is posted automatically from receipts.
 */
class StampDuty
{
    use Scoped;

    public const EXPENSE = '646100';

    public const PAYABLE = '447400';

    public function __construct(TenantContext $ctx, private AuditTrail $audit, private Ledger $ledger)
    {
        $this->ctx = $ctx;
    }

    public function rules(): array
    {
        return $this->table('stamp_duty_rules')->orderBy('code')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function saveRule(array $d): array
    {
        DB::table('stamp_duty_rules')->updateOrInsert(['hotel_id' => $this->hotelId(), 'code' => $d['code']], [
            'group_id' => $this->ctx->groupId(), 'name' => $d['name'], 'applies_to' => $d['applies_to'], 'kind' => $d['kind'], 'rate' => $d['rate'] ?? 0, 'fixed_amount' => $d['fixed_amount'] ?? 0,
            'min_base' => $d['min_base'] ?? 0, 'min_duty' => $d['min_duty'] ?? 0, 'active' => $d['active'] ?? true, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->audit->record('stamp_rule_saved', 'stamp_rule', $d['code'], $d);

        return (array) $this->table('stamp_duty_rules')->where('code', $d['code'])->first();
    }

    public function compute(string $code, int $base): array
    {
        $r = $this->table('stamp_duty_rules')->where(['code' => $code, 'active' => true])->first() ?? throw new DomainException('Unknown or inactive stamp duty rule.', 'unknown-rule');
        $amount = $base < $r->min_base ? 0 : ($r->kind === 'fixed' ? (int) $r->fixed_amount : (int) round($base * (float) $r->rate / 100));
        if ($amount > 0) {
            $amount = max($amount, (int) $r->min_duty);
        }

        return ['rule' => $r->code, 'base' => $base, 'amount' => $amount, 'rule_id' => $r->id];
    }

    public function record(string $code, int $base, string $date, ?string $sourceType = null, ?string $sourceId = null): array
    {
        $c = $this->compute($code, $base);
        if ($c['amount'] === 0) {
            return $c + ['entry_id' => null, 'id' => null];
        }
        $this->ensureAccounts([self::EXPENSE => ['Droits d\'enregistrement et de timbre', 6, 'expense'], self::PAYABLE => ['État - droits de timbre à reverser', 4, 'liability']]);

        return DB::transaction(function () use ($c, $date, $sourceType, $sourceId, $code) {
            $entry = $this->ledger->post('OD', $date, "Droit de timbre $code", [[self::EXPENSE, $c['amount']], [self::PAYABLE, -$c['amount']]], ['source_type' => 'stamp_duty', 'source_id' => $sourceId]);
            $id = DB::table('stamp_duty_entries')->insertGetId($this->tenant() + ['rule_id' => $c['rule_id'], 'source_type' => $sourceType, 'source_id' => $sourceId, 'date' => $date,
                'base' => $c['base'], 'amount' => $c['amount'], 'journal_entry_id' => $entry->id, 'created_by' => $this->actorId(), ...$this->stamp()]);
            $this->audit->record('stamp_duty_recorded', 'stamp_duty', $id, $c + ['entry' => $entry->id]);

            return $c + ['entry_id' => $entry->id, 'id' => $id];
        });
    }

    public function register(?string $from = null, ?string $to = null): array
    {
        return $this->table('stamp_duty_entries')->join('stamp_duty_rules as r', 'r.id', '=', 'stamp_duty_entries.rule_id')
            ->when($from, fn ($q, $v) => $q->where('stamp_duty_entries.date', '>=', $v))->when($to, fn ($q, $v) => $q->where('stamp_duty_entries.date', '<=', $v))
            ->orderByDesc('stamp_duty_entries.date')->get(['stamp_duty_entries.*', 'r.code as rule_code', 'r.name as rule_name'])->map(fn ($r) => (array) $r)->all();
    }
}

<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Accounting\Support\Sheets;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * VAT returns computed from the ledger (output VAT 443, deductible VAT 445) with the country profile's rate and form.
 * A draft can be recomputed; filing freezes the figures, posts the VAT settlement entry (443 / 445 against 444 due or 445800 credit
 * to carry forward) and locks the return. Corrections go through amend(), which supersedes the filed version and posts only the delta.
 * Taxable base and exempt turnover are derived from the VAT amount and class-70 revenue: to be reviewed by the chartered accountant.
 */
class VatReturns
{
    use Scoped;

    public const DUE = '444100';

    public const CREDIT = '445800';

    public function __construct(TenantContext $ctx, private Ledger $ledger, private CountryProfiles $profiles, private AuditTrail $audit)
    {
        $this->ctx = $ctx;
    }

    public function history(): array
    {
        return $this->table('vat_returns')->orderByDesc('period_from')->orderByDesc('version')->get()->map(fn ($r) => $this->decode($r, false))->all();
    }

    public function show(int $id): array
    {
        return $this->decode($this->find($id));
    }

    /** Compute without saving. */
    public function compute(string $from, string $to): array
    {
        $p = $this->profiles->profile();
        $rate = (float) $p['vat_rate'];
        $sum = fn (array $accounts, string $side) => (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.hotel_id', $this->hotelId())->whereBetween('l.date', [$from, $to])->whereIn('l.account_code', $accounts)
            ->where(fn ($q) => $q->whereNull('e.source_type')->orWhere('e.source_type', '!=', 'vat_settlement'))
            ->selectRaw($side === 'credit' ? 'coalesce(sum(l.credit) - sum(l.debit), 0) as v' : 'coalesce(sum(l.debit) - sum(l.credit), 0) as v')->first()->v;
        $collected = $sum($p['collected_accounts'], 'credit');
        $deductible = $sum($p['deductible_accounts'], 'debit');
        $revenue = (int) DB::table('journal_lines')->where('hotel_id', $this->hotelId())->whereBetween('date', [$from, $to])->where('account_code', 'like', '70%')
            ->selectRaw('coalesce(sum(credit) - sum(debit), 0) as v')->first()->v;
        $prior = max(0, (int) (DB::table('journal_lines')->where('hotel_id', $this->hotelId())->where('account_code', self::CREDIT)
            ->where('date', '<', $from)->selectRaw('coalesce(sum(debit) - sum(credit), 0) as v')->first()->v));
        $taxableBase = $rate > 0 ? (int) round($collected * 100 / $rate) : 0;
        $net = $collected - $deductible - $prior;
        $figures = [
            'turnover_total' => $revenue, 'turnover_taxable' => $taxableBase, 'turnover_exempt' => max(0, $revenue - $taxableBase), 'vat_collected' => $collected,
            'purchases_base' => $rate > 0 ? (int) round($deductible * 100 / $rate) : 0, 'vat_deductible' => $deductible, 'credit_brought_forward' => $prior,
            'vat_due' => max(0, $net), 'credit_to_carry' => max(0, -$net),
        ];

        return ['country' => $p['country'], 'form' => $p['vat_form'], 'rate' => $rate, 'period_from' => $from, 'period_to' => $to,
            'filing_deadline' => $this->deadline($to, (int) $p['filing_day']), 'figures' => $figures, 'lines' => $this->lines($figures, $p['country']),
            'fingerprint' => hash('sha256', json_encode($figures))];
    }

    /** Create or refresh the draft for a period. */
    public function prepare(string $from, string $to): array
    {
        if ($to < $from) {
            throw new DomainException('The period end is before its start.', 'bad-range');
        }
        $this->assertNoFilingOverlap($from, $to);
        $c = $this->compute($from, $to);
        $existing = $this->table('vat_returns')->where(['period_from' => $from, 'period_to' => $to, 'status' => 'draft'])->first();
        $row = ['country' => $c['country'], 'form_code' => $c['form'], 'rate' => $c['rate'], 'figures' => json_encode($c['figures']), 'lines' => json_encode($c['lines']),
            'fingerprint' => $c['fingerprint'], 'prepared_by' => $this->actorId(), 'updated_at' => now()];
        if ($existing) {
            DB::table('vat_returns')->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $id = DB::table('vat_returns')->insertGetId($row + $this->tenant() + ['period_from' => $from, 'period_to' => $to, 'created_at' => now()]);
        }
        $this->audit->record('vat_return_prepared', 'vat_return', $id, ['from' => $from, 'to' => $to, 'due' => $c['figures']['vat_due']]);

        return $this->show($id);
    }

    public function file(int $id, string $reference, ?string $filedOn = null): array
    {
        $r = $this->find($id);
        if ($r->status !== 'draft') {
            throw new DomainException('Only a draft return can be filed.', 'not-draft');
        }
        if (Carbon::parse($r->period_to)->isFuture()) {
            throw new DomainException('The period has not ended yet.', 'period-open');
        }
        $this->assertNoFilingOverlap($r->period_from, $r->period_to, $r->id);
        $this->ensureAccounts([self::DUE => ['État - TVA due', 4, 'liability'], self::CREDIT => ['État - crédit de TVA à reporter', 4, 'asset']]);

        return DB::transaction(function () use ($r, $reference, $filedOn) {
            $c = $this->compute($r->period_from, $r->period_to); // refreshed at filing: the filed figures are what the ledger says now
            $lines = $this->settlement($c['figures']);
            if ($r->amends_id) {
                $old = $this->find($r->amends_id);
                $lines = $this->delta($this->settlement(json_decode($old->figures, true)), $lines);
            }
            $entry = $lines ? $this->ledger->post('OD', $r->period_to, "Liquidation TVA {$r->period_from} au {$r->period_to}", $lines, ['source_type' => 'vat_settlement', 'source_id' => $r->id]) : null;
            DB::table('vat_returns')->where('id', $r->id)->update([
                'status' => 'filed', 'figures' => json_encode($c['figures']), 'lines' => json_encode($c['lines']), 'fingerprint' => $c['fingerprint'], 'filing_reference' => $reference,
                'filed_on' => $filedOn ?? now()->toDateString(), 'filed_by' => $this->actorId(), 'settlement_entry_id' => $entry?->id, 'updated_at' => now(),
            ]);
            if ($r->amends_id) {
                DB::table('vat_returns')->where('id', $r->amends_id)->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            $this->audit->record('vat_return_filed', 'vat_return', $r->id, ['reference' => $reference, 'due' => $c['figures']['vat_due'], 'credit' => $c['figures']['credit_to_carry'], 'entry' => $entry?->id]);

            return $this->show($r->id);
        });
    }

    /** Start a corrected version of a filed return. */
    public function amend(int $id): array
    {
        $r = $this->find($id);
        if ($r->status !== 'filed') {
            throw new DomainException('Only a filed return can be amended.', 'not-filed');
        }
        if ($this->table('vat_returns')->where('amends_id', $r->id)->where('status', 'draft')->exists()) {
            throw new DomainException('An amendment is already in preparation.', 'amendment-exists');
        }
        $c = $this->compute($r->period_from, $r->period_to);
        $new = DB::table('vat_returns')->insertGetId($this->tenant() + [
            'country' => $c['country'], 'period_from' => $r->period_from, 'period_to' => $r->period_to, 'version' => $r->version + 1, 'form_code' => $c['form'], 'rate' => $c['rate'],
            'figures' => json_encode($c['figures']), 'lines' => json_encode($c['lines']), 'fingerprint' => $c['fingerprint'], 'amends_id' => $r->id, 'prepared_by' => $this->actorId(), ...$this->stamp(),
        ]);
        $this->audit->record('vat_return_amended', 'vat_return', $new, ['amends' => $r->id]);

        return $this->show($new);
    }

    /** Has the ledger moved since the return was filed? */
    public function verify(int $id): array
    {
        $r = $this->find($id);
        $c = $this->compute($r->period_from, $r->period_to);

        return ['id' => $r->id, 'status' => $r->status, 'matches' => $c['fingerprint'] === $r->fingerprint, 'filed' => json_decode($r->figures, true), 'ledger_now' => $c['figures']];
    }

    public function export(int $id, string $format): array
    {
        $r = $this->find($id);
        $rows = [['code', 'label', 'amount']];
        foreach (json_decode($r->lines, true) as $l) {
            $rows[] = [$l['code'], $l['label'], $l['amount']];
        }
        $name = "vat-{$r->country}-{$r->period_from}-{$r->period_to}-v{$r->version}";

        return [$format === 'xlsx'
            ? Sheets::xlsx(['VAT' => array_merge([['form', $r->form_code], ['period', $r->period_from.' / '.$r->period_to], ['status', $r->status], ['reference', $r->filing_reference]], $rows)])
            : Sheets::csv($rows), $name];
    }

    // ---- internals ----

    /** Settlement lines (debit positive); balanced by construction. */
    private function settlement(array $f): array
    {
        $net = $f['vat_collected'] - $f['vat_deductible'] - $f['credit_brought_forward'];
        $out = [];
        $add = function (string $acc, int $signed, array $meta = []) use (&$out) {
            if ($signed !== 0) {
                $out[] = [$acc, $signed, $meta];
            }
        };
        $add('443100', $f['vat_collected']);
        $add('445200', -$f['vat_deductible']);
        $add(self::CREDIT, -$f['credit_brought_forward']);
        $add(self::CREDIT, max(0, -$net));
        $add(self::DUE, -max(0, $net));

        return $out;
    }

    private function delta(array $old, array $new): array
    {
        $sum = [];
        foreach ($new as [$a, $v]) {
            $sum[$a] = ($sum[$a] ?? 0) + $v;
        }
        foreach ($old as [$a, $v]) {
            $sum[$a] = ($sum[$a] ?? 0) - $v;
        }

        return array_values(array_filter(array_map(fn ($a, $v) => $v === 0 ? null : [$a, $v, []], array_keys($sum), $sum)));
    }

    private function lines(array $f, string $country): array
    {
        $l = fn (string $code, string $label, int $amount) => ['code' => $code, 'label' => $label, 'amount' => $amount];

        return [
            $l('A1', 'Chiffre d\'affaires total', $f['turnover_total']), $l('A2', 'Chiffre d\'affaires imposable (base)', $f['turnover_taxable']), $l('A3', 'Opérations exonérées / hors champ', $f['turnover_exempt']),
            $l('B1', 'TVA collectée', $f['vat_collected']), $l('C0', 'Base des achats déductibles', $f['purchases_base']), $l('C1', 'TVA déductible sur achats et services', $f['vat_deductible']),
            $l('D1', 'Crédit de TVA antérieur', $f['credit_brought_forward']), $l('E1', 'TVA à payer', $f['vat_due']), $l('F1', 'Crédit de TVA à reporter', $f['credit_to_carry']),
        ];
    }

    private function deadline(string $to, int $day): string
    {
        return Carbon::parse($to)->addMonthNoOverflow()->startOfMonth()->day(min($day, 28))->toDateString();
    }

    private function assertNoFilingOverlap(string $from, string $to, ?int $except = null): void
    {
        $q = $this->table('vat_returns')->where('status', 'filed')->where('period_from', '<=', $to)->where('period_to', '>=', $from)
            ->when($except, fn ($q, $v) => $q->where('id', '!=', $v));
        if ($row = $q->first()) {
            if (! ($except && $this->find($except)->amends_id === $row->id)) {
                throw new DomainException("The period overlaps a filed return (#{$row->id}); amend it instead.", 'already-filed');
            }
        }
    }

    private function find(int $id): object
    {
        return $this->table('vat_returns')->where('id', $id)->first() ?? throw new DomainException('VAT return not found.', 'not-found');
    }

    private function decode(object $r, bool $full = true): array
    {
        $a = (array) $r;
        $a['figures'] = json_decode($r->figures, true);
        $a['lines'] = json_decode($r->lines, true);
        $a['locked'] = $r->status !== 'draft';
        if (! $full) {
            unset($a['lines']);
        }

        return $a;
    }
}

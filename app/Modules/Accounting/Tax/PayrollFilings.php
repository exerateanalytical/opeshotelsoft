<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Accounting\Support\Sheets;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll-tax declarations (Cameroon: CNPS, IRPP/CAC, CFC/FNE) exported as Excel/CSV from approved or paid payroll runs.
 * Column layouts follow the payslip line codes of the Cameroon rule pack; the official portal templates must be matched by the
 * payroll officer and chartered accountant (import formats of CNPS/DGI are not reproduced here).
 */
class PayrollFilings
{
    use Scoped;

    public const KINDS = ['cnps', 'irpp', 'cfc_fne_cac'];

    public function __construct(TenantContext $ctx, private AuditTrail $audit, private CountryProfiles $profiles)
    {
        $this->ctx = $ctx;
    }

    /** @return array{kind:string, period:string, form:string, header:array, rows:array, totals:array} */
    public function build(string $kind, string $period): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new DomainException('Unknown filing kind.', 'unknown-kind');
        }
        $profile = $this->profiles->profile();
        if (! isset($profile['payroll_forms'][$kind])) {
            throw new DomainException("Payroll declarations are not configured for {$profile['name']}.", 'unsupported-country');
        }
        $run = $this->table('payroll_runs')->where('period', $period)->whereIn('status', ['approved', 'paid'])->first()
            ?? throw new DomainException('No approved payroll run for this period.', 'no-run');
        $slips = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')->where('p.payroll_run_id', $run->id)->orderBy('e.number')
            ->get(['p.*', 'e.number', 'e.first_name', 'e.last_name', 'e.cnps_number', 'e.tax_number']);
        $amt = fn (object $s, string $code) => (int) collect(json_decode($s->lines, true))->where('code', $code)->sum('amount');
        $basis = fn (object $s, string $code) => (int) (collect(json_decode($s->lines, true))->firstWhere('code', $code)['basis'] ?? 0);

        $rows = [];
        foreach ($slips as $s) {
            $name = trim("$s->last_name $s->first_name");
            $rows[] = match ($kind) {
                'cnps' => ['number' => $s->number, 'cnps_number' => $s->cnps_number, 'name' => $name, 'gross' => (int) $s->gross, 'cnps_base' => $basis($s, 'CNPS'),
                    'employee_pension' => $amt($s, 'CNPS'), 'employer_pension' => $amt($s, 'CNPS_PV'), 'employer_family' => $amt($s, 'CNPS_PF'), 'employer_accident' => $amt($s, 'CNPS_AT'),
                    'total_due' => $amt($s, 'CNPS') + $amt($s, 'CNPS_PV') + $amt($s, 'CNPS_PF') + $amt($s, 'CNPS_AT')],
                'irpp' => ['number' => $s->number, 'niu' => $s->tax_number, 'name' => $name, 'taxable' => (int) $s->taxable, 'irpp' => $amt($s, 'IRPP'), 'cac' => $amt($s, 'CAC'),
                    'rav' => $amt($s, 'RAV'), 'tdl' => $amt($s, 'TDL'), 'total_due' => $amt($s, 'IRPP') + $amt($s, 'CAC') + $amt($s, 'RAV') + $amt($s, 'TDL')],
                'cfc_fne_cac' => ['number' => $s->number, 'niu' => $s->tax_number, 'name' => $name, 'taxable' => (int) $s->taxable, 'cfc_employee' => $amt($s, 'CFC'), 'cfc_employer' => $amt($s, 'CFC_E'),
                    'fne_employer' => $amt($s, 'FNE'), 'cac' => $amt($s, 'CAC'), 'total_due' => $amt($s, 'CFC') + $amt($s, 'CFC_E') + $amt($s, 'FNE') + $amt($s, 'CAC')],
            };
        }
        $totals = [];
        foreach ($rows[0] ?? [] as $k => $v) {
            if (is_int($v) && ! in_array($k, ['number'], true)) {
                $totals[$k] = array_sum(array_column($rows, $k));
            }
        }
        $hotel = DB::table('hotels')->find($this->hotelId());

        return ['kind' => $kind, 'period' => $period, 'form' => $profile['payroll_forms'][$kind], 'employer' => ['name' => $hotel->name ?? '', 'tax_id' => $hotel->tax_id ?? null],
            'run_id' => $run->id, 'header' => array_keys($rows[0] ?? []), 'rows' => $rows, 'totals' => $totals];
    }

    /** Render and log a filing export. @return array{0:string,1:string,2:int} content, filename, filing id */
    public function export(string $kind, string $period, string $format): array
    {
        $b = $this->build($kind, $period);
        $matrix = array_merge([$b['header']], array_map('array_values', $b['rows']), [array_merge(['TOTAL'], array_map(fn ($h) => $b['totals'][$h] ?? '', array_slice($b['header'], 1)))]);
        $content = $format === 'xlsx' ? Sheets::xlsx([strtoupper($kind) => array_merge([[$b['form']], ['period', $period]], $matrix)]) : Sheets::csv($matrix);
        $id = DB::table('payroll_filings')->insertGetId($this->tenant() + ['kind' => $kind, 'period' => $period, 'employees' => count($b['rows']), 'totals' => json_encode($b['totals']),
            'file_hash' => hash('sha256', $content), 'generated_by' => $this->actorId(), ...$this->stamp()]);
        $this->audit->record('payroll_filing_generated', 'payroll_filing', $id, ['kind' => $kind, 'period' => $period, 'employees' => count($b['rows'])]);

        return [$content, "payroll-$kind-$period", $id];
    }

    public function history(): array
    {
        return $this->table('payroll_filings')->orderByDesc('id')->limit(200)->get()->map(fn ($r) => (array) $r + ['totals' => json_decode($r->totals, true)])->all();
    }

    public function markFiled(int $id, string $reference, ?string $on = null): array
    {
        $this->table('payroll_filings')->where('id', $id)->exists() || throw new DomainException('Filing not found.', 'not-found');
        $this->table('payroll_filings')->where('id', $id)->update(['status' => 'filed', 'filing_reference' => $reference, 'filed_on' => $on ?? now()->toDateString(), 'updated_at' => now()]);
        $this->audit->record('payroll_filing_filed', 'payroll_filing', $id, ['reference' => $reference]);

        return (array) DB::table('payroll_filings')->find($id);
    }
}

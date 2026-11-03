<?php

namespace Tests\Feature\Accounting;

use App\Modules\HR\Application\Payroll\CameroonRulePack;
use App\Modules\HR\Application\Payroll\PayslipCalculator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxPayrollFilingsTest extends TestCase
{
    private array $slips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pack = DB::table('payroll_rule_packs')->insertGetId(['country' => 'CM', 'version' => 'test', 'effective_from' => '2026-01-01', 'params' => json_encode(CameroonRulePack::params()), 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('payroll_runs')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'period' => '2026-10', 'payroll_rule_pack_id' => $pack, 'status' => 'approved',
            'gross' => 0, 'net' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $calc = new PayslipCalculator(CameroonRulePack::params());
        $totals = ['gross' => 0, 'net' => 0, 'employee_social' => 0, 'employer_social' => 0, 'taxes_withheld' => 0, 'other_employer' => 0, 'advances_recovered' => 0];
        foreach ([['Mballa', 'Jean', 350_000, 'CNPS-1', 'M0001'], ['Ngo', 'Marie', 900_000, 'CNPS-2', 'M0002']] as $i => [$last, $first, $base, $cnps, $niu]) {
            $emp = DB::table('employees')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => 'E'.($i + 1), 'first_name' => $first, 'last_name' => $last,
                'cnps_number' => $cnps, 'tax_number' => $niu, 'hire_date' => '2025-01-01', 'base_salary' => $base, 'created_at' => now(), 'updated_at' => now()]);
            $s = $calc->compute($base);
            DB::table('payslips')->insert(['payroll_run_id' => $run, 'employee_id' => $emp, 'gross' => $s['gross'], 'taxable' => $s['taxable'], 'net' => $s['net'], 'employee_social' => $s['employee_social'],
                'employer_social' => $s['employer_social'], 'taxes_withheld' => $s['taxes_withheld'], 'other_employer' => $s['other_employer'], 'advances_recovered' => 0, 'lines' => json_encode($s['lines']), 'created_at' => now(), 'updated_at' => now()]);
            $this->slips[] = $s;
            foreach ($totals as $k => $_) {
                $totals[$k] += $s[$k];
            }
        }
        DB::table('payroll_runs')->where('id', $run)->update($totals);
    }

    public function test_cnps_irpp_and_cfc_declarations_match_the_payslips(): void
    {
        $code = fn (array $s, string $c) => (int) collect($s['lines'])->where('code', $c)->sum('amount');

        $cnps = $this->getJson('/api/v1/accounting3/tax/payroll-filings/cnps?period=2026-10')->assertOk()->json();
        $this->assertCount(2, $cnps['rows']);
        $this->assertSame('CNPS-1', $cnps['rows'][0]['cnps_number']);
        $this->assertSame($code($this->slips[0], 'CNPS'), $cnps['rows'][0]['employee_pension']);
        $this->assertSame($this->slips[0]['employer_social'] + $this->slips[0]['employee_social'], $cnps['rows'][0]['total_due']);
        $this->assertSame($this->slips[0]['employer_social'] + $this->slips[1]['employer_social'] + $this->slips[0]['employee_social'] + $this->slips[1]['employee_social'], $cnps['totals']['total_due']);

        $irpp = $this->getJson('/api/v1/accounting3/tax/payroll-filings/irpp?period=2026-10')->assertOk()->json();
        $this->assertSame('M0002', $irpp['rows'][1]['niu']);
        $this->assertSame($code($this->slips[1], 'IRPP'), $irpp['rows'][1]['irpp']);
        $this->assertSame($code($this->slips[1], 'CAC'), $irpp['rows'][1]['cac']);
        $this->assertGreaterThan(0, $irpp['totals']['irpp']);

        $cfc = $this->getJson('/api/v1/accounting3/tax/payroll-filings/cfc_fne_cac?period=2026-10')->assertOk()->json();
        $this->assertSame($code($this->slips[0], 'FNE') + $code($this->slips[1], 'FNE'), $cfc['totals']['fne_employer']);
        $this->assertSame($code($this->slips[0], 'CFC') + $code($this->slips[1], 'CFC'), $cfc['totals']['cfc_employee']);
    }

    public function test_excel_and_csv_exports_are_logged_and_can_be_marked_filed(): void
    {
        $x = $this->get('/api/v1/accounting3/tax/payroll-filings/cnps/export?period=2026-10&format=xlsx')->assertOk();
        $this->assertStringStartsWith('PK', $x->getContent());
        $csv = $this->get('/api/v1/accounting3/tax/payroll-filings/irpp/export?period=2026-10&format=csv')->assertOk();
        $this->assertStringContainsString('Mballa Jean', $csv->getContent());
        $this->assertStringContainsString('TOTAL', $csv->getContent());

        $hist = $this->getJson('/api/v1/accounting3/tax/payroll-filings')->assertOk()->json();
        $this->assertCount(2, $hist);
        $this->assertSame(64, strlen($hist[0]['file_hash']));
        $this->postJson("/api/v1/accounting3/tax/payroll-filings/{$hist[0]['id']}/filed", ['reference' => 'CNPS-OCT'])->assertOk()->assertJsonPath('status', 'filed');
    }

    public function test_guards_missing_run_unknown_kind_unsupported_country_and_permission(): void
    {
        $this->getJson('/api/v1/accounting3/tax/payroll-filings/cnps?period=2026-09')->assertStatus(422);
        $this->getJson('/api/v1/accounting3/tax/payroll-filings/dgi?period=2026-10')->assertStatus(422);
        DB::table('payroll_runs')->update(['status' => 'draft']);
        $this->getJson('/api/v1/accounting3/tax/payroll-filings/cnps?period=2026-10')->assertStatus(422); // drafts are never declared
        DB::table('payroll_runs')->update(['status' => 'paid']);
        $this->putJson('/api/v1/accounting3/tax/profile', ['country' => 'GA'])->assertOk();
        $this->getJson('/api/v1/accounting3/tax/payroll-filings/cnps?period=2026-10')->assertStatus(422); // only Cameroon forms are built
        $r = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($r, $this->hotel);
        $this->getJson('/api/v1/accounting3/tax/payroll-filings')->assertForbidden();
    }
}

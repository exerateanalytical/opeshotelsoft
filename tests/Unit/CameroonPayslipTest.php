<?php

namespace Tests\Unit;

use App\Modules\HR\Application\Payroll\CameroonRulePack;
use App\Modules\HR\Application\Payroll\PayslipCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples for the Cameroon pack. Expected figures follow the
 * rules as coded; an accountant must confirm them before certification.
 */
class CameroonPayslipTest extends TestCase
{
    private function calc(): PayslipCalculator
    {
        return new PayslipCalculator(CameroonRulePack::params());
    }

    public function test_salary_below_irpp_threshold_pays_no_income_tax(): void
    {
        $s = $this->calc()->compute(60_000);

        $this->assertSame(60_000, $s['gross']);
        $this->assertSame(2_520, $s['employee_social']); // 4.2%
        $this->assertSame(0, collect($s['lines'])->firstWhere('code', 'IRPP')['amount'] ?? 0);
        $this->assertSame($s['gross'] - $s['employee_social'] - $s['taxes_withheld'], $s['net']);
    }

    public function test_mid_salary_payslip(): void
    {
        $s = $this->calc()->compute(300_000, [
            ['code' => 'LOG', 'name' => 'Indemnité de logement', 'amount' => 50_000, 'taxable' => true, 'cnps' => true],
            ['code' => 'TRANS', 'name' => 'Indemnité de transport', 'amount' => 20_000, 'taxable' => false, 'cnps' => false],
        ]);

        $this->assertSame(370_000, $s['gross']);
        $this->assertSame(350_000, $s['taxable']);
        $this->assertSame(14_700, $s['employee_social']); // 4.2% × 350 000
        // Annual taxable: (350 000 × 0.7 − 14 700) × 12 − 500 000 = 2 263 600
        // IRPP: 2 000 000 × 10% + 263 600 × 15% = 239 540 / 12 = 19 962
        $irpp = collect($s['lines'])->firstWhere('code', 'IRPP')['amount'];
        $this->assertSame(19_962, $irpp);
        $this->assertSame(1_996, collect($s['lines'])->firstWhere('code', 'CAC')['amount']);
        $this->assertSame(4_550, collect($s['lines'])->firstWhere('code', 'RAV')['amount']); // gross 370 000: 300–400k band
        $this->assertSame(2_000, collect($s['lines'])->firstWhere('code', 'TDL')['amount']);
        $this->assertSame($s['gross'] - $s['employee_social'] - $s['taxes_withheld'], $s['net']);
    }

    public function test_cnps_is_capped_at_ceiling(): void
    {
        $s = $this->calc()->compute(2_000_000);

        $this->assertSame(31_500, $s['employee_social']); // 4.2% × 750 000
        $this->assertSame((int) round(750_000 * (4.2 + 7 + 1.75) / 100), $s['employer_social']);
    }

    public function test_overtime_and_advance(): void
    {
        $s = $this->calc()->compute(173_330, [], overtimeHours: 10, advance: 20_000);

        // hourly 1 000; 8h × 1.2 + 2h × 1.3 = 12.2 h-equivalents
        $this->assertSame(12_200, collect($s['lines'])->firstWhere('code', 'HS')['amount']);
        $this->assertSame(20_000, $s['advances_recovered']);
    }
}

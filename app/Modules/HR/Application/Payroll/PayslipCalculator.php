<?php

namespace App\Modules\HR\Application\Payroll;

use App\Platform\Domain\DomainException;

/**
 * Computes one monthly payslip from a rule pack. Pure function: no DB,
 * so it is easy to certify against an accountant's worked examples.
 *
 * Inputs: base salary, allowances [{code,name,amount,taxable,cnps}],
 * overtime hours, bonuses, service charge share, advance deduction.
 */
class PayslipCalculator
{
    public function __construct(private array $params) {}

    /**
     * @return array{gross:int, taxable:int, net:int, employee_social:int, employer_social:int, taxes_withheld:int, other_employer:int, advances_recovered:int, lines:array}
     */
    public function compute(int $base, array $allowances = [], float|array $overtimeHours = 0, int $bonuses = 0, int $serviceCharge = 0, int $advance = 0): array
    {
        $p = $this->params;
        if (! isset($p['cnps'], $p['irpp'])) {
            throw new DomainException('This payroll rule pack is incomplete.');
        }
        $lines = [];
        $add = function (string $section, string $code, string $label, int $amount, ?float $rate = null, ?int $basis = null) use (&$lines) {
            if ($amount !== 0) {
                $lines[] = compact('section', 'code', 'label', 'amount', 'rate', 'basis');
            }
        };

        // Earnings
        $add('earning', 'BASE', 'Salaire de base', $base);
        $taxable = $base;
        $cnpsBase = $base;
        foreach ($allowances as $a) {
            $amt = (int) $a['amount'];
            $add('earning', $a['code'], $a['name'], $amt);
            if ($a['taxable'] ?? true) {
                $taxable += $amt;
            }
            if ($a['cnps'] ?? true) {
                $cnpsBase += $amt;
            }
        }
        // Overtime: a float is weekday hours (legacy); an array splits hours by category
        // ['weekday'=>h, 'night'=>h, 'sunday'=>h, 'holiday'=>h], each with its rule-pack multiplier.
        $ot = is_array($overtimeHours) ? $overtimeHours : ['weekday' => $overtimeHours];
        $hourly = $base / $p['monthly_hours'];
        $o = $p['overtime'];
        $categories = [
            'weekday' => ['HS', 'Heures supplémentaires', null],
            'night' => ['HS_NUIT', 'Heures supplémentaires de nuit', $o['night'] ?? $o['night_or_sunday'] ?? 1.5],
            'sunday' => ['HS_DIM', 'Heures supplémentaires dimanche', $o['sunday'] ?? $o['night_or_sunday'] ?? 1.5],
            'holiday' => ['HS_FERIE', 'Heures supplémentaires jour férié', $o['holiday'] ?? $o['night_or_sunday'] ?? 1.5],
        ];
        foreach ($categories as $cat => [$code, $label, $mult]) {
            $h = (float) ($ot[$cat] ?? 0);
            if ($h <= 0) {
                continue;
            }
            $amount = (int) round(($mult === null ? $this->overtime($h) : $h * $mult) * $hourly);
            $add('earning', $code, $label.' ('.$h.' h)', $amount, $mult, null);
            $taxable += $amount;
            $cnpsBase += $amount;
        }
        foreach ([['PRIME', 'Primes', $bonuses], ['SVC', 'Service (pourboires répartis)', $serviceCharge]] as [$code, $label, $amt]) {
            $add('earning', $code, $label, $amt);
            $taxable += $amt;
            $cnpsBase += $amt;
        }
        $gross = (int) collect($lines)->where('section', 'earning')->sum('amount');

        // Social security (CNPS)
        $cnpsCapped = min($cnpsBase, $p['cnps']['ceiling']);
        $empPension = $this->pct($cnpsCapped, $p['cnps']['employee_pension']);
        $add('employee_deduction', 'CNPS', 'CNPS pension (salarié)', $empPension, $p['cnps']['employee_pension'], $cnpsCapped);
        $erPension = $this->pct($cnpsCapped, $p['cnps']['employer_pension']);
        $erFamily = $this->pct($cnpsCapped, $p['cnps']['employer_family']);
        $erAccident = $this->pct($cnpsCapped, $p['cnps']['employer_accident']);
        $add('employer_charge', 'CNPS_PV', 'CNPS pension (employeur)', $erPension, $p['cnps']['employer_pension'], $cnpsCapped);
        $add('employer_charge', 'CNPS_PF', 'CNPS prestations familiales', $erFamily, $p['cnps']['employer_family'], $cnpsCapped);
        $add('employer_charge', 'CNPS_AT', 'CNPS accidents du travail', $erAccident, $p['cnps']['employer_accident'], $cnpsCapped);

        // Income tax (IRPP + CAC), computed on the annualised taxable salary.
        $irpp = 0;
        if ($gross >= $p['irpp']['monthly_exemption_threshold']) {
            $annual = ($taxable * (1 - $p['irpp']['professional_deduction_percent'] / 100) - $empPension) * 12 - $p['irpp']['annual_abatement'];
            $irpp = (int) round($this->progressive(max(0, $annual), $p['irpp']['annual_brackets']) / 12);
        }
        $cac = $this->pct($irpp, $p['irpp']['cac_percent']);
        $add('employee_deduction', 'IRPP', 'IRPP', $irpp);
        $add('employee_deduction', 'CAC', 'Centimes additionnels communaux', $cac, $p['irpp']['cac_percent'], $irpp);

        $cfc = $this->pct($taxable, $p['cfc']['employee']);
        $add('employee_deduction', 'CFC', 'Crédit Foncier (salarié)', $cfc, $p['cfc']['employee'], $taxable);
        $rav = $this->bracketAmount($gross, $p['rav']);
        $add('employee_deduction', 'RAV', 'Redevance audiovisuelle', $rav);
        $tdl = $this->bracketAmount($base, $p['tdl']);
        $add('employee_deduction', 'TDL', 'Taxe de développement local', $tdl);

        $erCfc = $this->pct($taxable, $p['cfc']['employer']);
        $fne = $this->pct($taxable, $p['fne_employer']);
        $add('employer_charge', 'CFC_E', 'Crédit Foncier (employeur)', $erCfc, $p['cfc']['employer'], $taxable);
        $add('employer_charge', 'FNE', 'Fonds National de l\'Emploi', $fne, $p['fne_employer'], $taxable);

        $taxes = $irpp + $cac + $cfc + $rav + $tdl;
        $net = $gross - $empPension - $taxes;
        $advance = min($advance, max(0, $net));
        $add('employee_deduction', 'AVANCE', 'Remboursement avance / prêt', $advance);
        $net -= $advance;

        return [
            'gross' => $gross,
            'taxable' => $taxable,
            'net' => $net,
            'employee_social' => $empPension,
            'employer_social' => $erPension + $erFamily + $erAccident,
            'taxes_withheld' => $taxes,
            'other_employer' => $erCfc + $fne,
            'advances_recovered' => $advance,
            'lines' => $lines,
        ];
    }

    private function overtime(float $hours): float
    {
        $o = $this->params['overtime'];
        $first = min($hours, 8);
        $next = min(max($hours - 8, 0), 8);
        $beyond = max($hours - 16, 0);

        return $first * $o['first_8h'] + $next * $o['next_8h'] + $beyond * $o['beyond'];
    }

    private function progressive(float $income, array $brackets): float
    {
        $tax = 0;
        $lower = 0;
        foreach ($brackets as [$upper, $rate]) {
            $top = $upper ?? PHP_INT_MAX;
            if ($income > $lower) {
                $tax += (min($income, $top) - $lower) * $rate / 100;
            }
            $lower = $top;
        }

        return $tax;
    }

    private function bracketAmount(int $value, array $table): int
    {
        foreach ($table as [$upper, $amount]) {
            if ($upper === null || $value <= $upper) {
                return $amount;
            }
        }

        return 0;
    }

    private function pct(int $base, float $rate): int
    {
        return (int) round($base * $rate / 100);
    }
}

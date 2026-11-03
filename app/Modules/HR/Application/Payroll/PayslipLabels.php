<?php

namespace App\Modules\HR\Application\Payroll;

use App\Modules\HR\Domain\Payslip;

/** Payslip labels in the employee's language (fr/en). W05 renders the PDF from this. */
final class PayslipLabels
{
    private const LINES = [
        'BASE' => ['Salaire de base', 'Base salary'],
        'HS' => ['Heures supplémentaires', 'Overtime'],
        'HS_NUIT' => ['Heures supplémentaires de nuit', 'Night overtime'],
        'HS_DIM' => ['Heures supplémentaires dimanche', 'Sunday overtime'],
        'HS_FERIE' => ['Heures supplémentaires jour férié', 'Public holiday overtime'],
        'PRIME' => ['Primes', 'Bonuses'],
        'SVC' => ['Service (pourboires répartis)', 'Service charge share'],
        'CNPS' => ['CNPS pension (salarié)', 'CNPS pension (employee)'],
        'CNPS_PV' => ['CNPS pension (employeur)', 'CNPS pension (employer)'],
        'CNPS_PF' => ['CNPS prestations familiales', 'CNPS family allowances'],
        'CNPS_AT' => ['CNPS accidents du travail', 'CNPS work accident'],
        'IRPP' => ['IRPP', 'Personal income tax (IRPP)'],
        'CAC' => ['Centimes additionnels communaux', 'Municipal surtax (CAC)'],
        'CFC' => ['Crédit Foncier (salarié)', 'Housing fund CFC (employee)'],
        'CFC_E' => ['Crédit Foncier (employeur)', 'Housing fund CFC (employer)'],
        'FNE' => ['Fonds National de l\'Emploi', 'National Employment Fund'],
        'RAV' => ['Redevance audiovisuelle', 'Broadcasting levy (RAV)'],
        'TDL' => ['Taxe de développement local', 'Local development tax'],
        'AVANCE' => ['Remboursement avance / prêt', 'Advance / loan repayment'],
    ];

    private const HEADINGS = [
        'title' => ['Bulletin de paie', 'Payslip'],
        'period' => ['Période', 'Period'],
        'employee' => ['Salarié', 'Employee'],
        'number' => ['Matricule', 'Employee number'],
        'earning' => ['Gains', 'Earnings'],
        'employee_deduction' => ['Retenues salariales', 'Employee deductions'],
        'employer_charge' => ['Charges patronales', 'Employer contributions'],
        'gross' => ['Salaire brut', 'Gross pay'],
        'taxable' => ['Salaire imposable', 'Taxable pay'],
        'net' => ['Net à payer', 'Net pay'],
        'rate' => ['Taux', 'Rate'],
        'basis' => ['Base', 'Basis'],
        'amount' => ['Montant', 'Amount'],
        'hours' => ['h', 'h'],
    ];

    public static function localise(Payslip $slip, string $lang): array
    {
        $i = $lang === 'en' ? 1 : 0;
        $lines = collect($slip->lines)->map(function ($l) use ($i) {
            if (isset(self::LINES[$l['code']])) {
                $hours = preg_match('/\(([\d.]+) h\)$/', $l['label'], $m) ? ' ('.$m[1].' h)' : '';
                $l['label'] = self::LINES[$l['code']][$i].$hours;
            }

            return $l;
        })->all();

        return [
            'language' => $i ? 'en' : 'fr',
            'labels' => array_map(fn ($pair) => $pair[$i], self::HEADINGS),
            'lines' => $lines,
        ];
    }
}

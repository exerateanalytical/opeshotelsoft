<?php

namespace App\Modules\HR\Application\Payroll;

/**
 * Default Cameroon payroll parameters (monthly, XAF).
 *
 * NOT CERTIFIED. Values are from publicly available sources and must be
 * verified by a Cameroonian chartered accountant / payroll expert against
 * the current Finance Law, CNPS circulars and the hotel's collective
 * agreement before any real payroll is run (docs/BLOCKERS.md).
 */
final class CameroonRulePack
{
    public static function params(): array
    {
        return [
            'currency' => 'XAF',
            'cnps' => [
                'ceiling' => 750_000,               // monthly contribution ceiling
                'employee_pension' => 4.2,          // PVID salarié
                'employer_pension' => 4.2,          // PVID employeur
                'employer_family' => 7.0,           // prestations familiales (régime général)
                'employer_accident' => 1.75,        // risques professionnels (groupe A)
            ],
            'cfc' => ['employee' => 1.0, 'employer' => 1.5], // Crédit Foncier du Cameroun
            'fne_employer' => 1.0,                           // Fonds National de l'Emploi
            'irpp' => [
                'monthly_exemption_threshold' => 62_000,     // no IRPP below this gross
                'professional_deduction_percent' => 30,
                'annual_abatement' => 500_000,
                'annual_brackets' => [                      // [upper bound, rate %]
                    [2_000_000, 10],
                    [3_000_000, 15],
                    [5_000_000, 25],
                    [null, 35],
                ],
                'cac_percent' => 10,                         // Centimes additionnels communaux on IRPP
            ],
            'rav' => [ // Redevance audiovisuelle: [upper gross bound, monthly amount]
                [50_000, 0], [100_000, 750], [200_000, 1_950], [300_000, 3_250], [400_000, 4_550],
                [500_000, 5_850], [600_000, 7_150], [700_000, 8_450], [800_000, 9_750],
                [900_000, 11_050], [1_000_000, 12_350], [null, 13_000],
            ],
            'tdl' => [ // Taxe de développement local: [upper base-salary bound, monthly amount]
                [62_000, 0], [75_000, 250], [100_000, 500], [125_000, 750], [150_000, 1_000],
                [200_000, 1_250], [250_000, 1_500], [300_000, 2_000], [500_000, 2_250], [null, 2_500],
            ],
            // Overtime multipliers (UNCERTIFIED, payroll expert must confirm against Décret 95/677 and the
            // hotel's collective agreement). Weekday hours use the tiers; night, Sunday and public
            // holiday hours use their own flat multiplier. Night window is local time.
            'overtime' => [
                'first_8h' => 1.2, 'next_8h' => 1.3, 'beyond' => 1.4, 'night_or_sunday' => 1.5,
                'night' => 1.5, 'sunday' => 1.5, 'holiday' => 2.0,
                'night_start' => '22:00', 'night_end' => '06:00',
                'standard_day_minutes' => 480, // used when no shift is scheduled
                'certified' => false, 'note' => 'uncertified, payroll expert must confirm',
            ],
            // Fixed-date public holidays (MM-DD). Movable religious holidays (Good Friday, Ascension,
            // Eid al-Fitr, Eid al-Adha) are entered per year by an admin. UNCERTIFIED.
            'public_holidays_fixed' => [
                ['01-01', "Jour de l'an"], ['02-11', 'Fête de la Jeunesse'], ['05-01', 'Fête du Travail'],
                ['05-20', 'Fête Nationale'], ['08-15', 'Assomption'], ['12-25', 'Noël'],
            ],
            'monthly_hours' => 173.33,
        ];
    }
}

<?php

namespace App\Modules\HR\Application\Lifecycle;

/**
 * Built-in contract templates (Cameroon Labour Code, law 92/007). A group can
 * override any of them in hr_contract_templates. Placeholders: {{employee.full_name}},
 * {{employee.number}}, {{employee.date_of_birth}}, {{employee.id_number}}, {{employee.address}},
 * {{employee.nationality}}, {{position.title}}, {{position.category}}, {{department.name}},
 * {{contract.kind}}, {{contract.start_date}}, {{contract.end_date}}, {{contract.probation_end}},
 * {{contract.hours_per_week}}, {{salary.amount}}, {{salary.words}}, {{hotel.name}}, {{hotel.legal_name}},
 * {{hotel.city}}, {{hotel.address}}, {{today}}; amendments also {{change.*}}.
 */
class ContractTemplates
{
    public const KINDS = ['CDI', 'CDD', 'stage', 'journalier', 'avenant'];

    public const PLACEHOLDERS = [
        'employee.full_name', 'employee.number', 'employee.date_of_birth', 'employee.id_number', 'employee.address', 'employee.nationality',
        'position.title', 'position.category', 'department.name',
        'contract.kind', 'contract.start_date', 'contract.end_date', 'contract.probation_end', 'contract.hours_per_week',
        'salary.amount', 'salary.words', 'hotel.name', 'hotel.legal_name', 'hotel.city', 'hotel.address', 'today',
        'change.type', 'change.from_salary', 'change.to_salary', 'change.from_position', 'change.to_position', 'change.effective_date',
    ];

    private const PARTIES_FR = "Entre les soussignés :\n{{hotel.legal_name}}, {{hotel.address}} {{hotel.city}}, ci-après « l'Employeur »,\net {{employee.full_name}}, né(e) le {{employee.date_of_birth}}, pièce d'identité n° {{employee.id_number}}, demeurant {{employee.address}}, ci-après « le Salarié ».\n\n";

    private const PARTIES_EN = "Between:\n{{hotel.legal_name}}, {{hotel.address}} {{hotel.city}}, hereinafter \"the Employer\",\nand {{employee.full_name}}, born on {{employee.date_of_birth}}, ID no. {{employee.id_number}}, residing at {{employee.address}}, hereinafter \"the Employee\".\n\n";

    private const END_FR = "\n\nLe présent contrat est régi par la loi n° 92/007 du 14 août 1992 portant Code du travail et la convention collective applicable.\nFait à {{hotel.city}}, le {{today}}.";

    private const END_EN = "\n\nThis contract is governed by Law No. 92/007 of 14 August 1992 (Labour Code) and the applicable collective agreement.\nDone at {{hotel.city}} on {{today}}.";

    /** @return array{title:string, body:string}|null */
    public static function default(string $kind, string $locale): ?array
    {
        $fr = $locale !== 'en';
        $job = $fr
            ? "Article 1 – Fonction : le Salarié est engagé en qualité de {{position.title}} (catégorie {{position.category}}), service {{department.name}}.\n"
            : "Article 1 – Position: the Employee is hired as {{position.title}} (category {{position.category}}), department {{department.name}}.\n";
        $pay = $fr
            ? "Article 3 – Rémunération : salaire de base mensuel brut de {{salary.amount}} FCFA ({{salary.words}} francs CFA), pour {{contract.hours_per_week}} heures par semaine.\n"
            : "Article 3 – Pay: gross monthly base salary of {{salary.amount}} XAF ({{salary.words}} CFA francs), for {{contract.hours_per_week}} hours per week.\n";
        $bodies = [
            'CDI' => [
                'Contrat de travail à durée indéterminée', 'Permanent employment contract',
                "Article 2 – Durée : contrat à durée indéterminée prenant effet le {{contract.start_date}}, avec une période d'essai jusqu'au {{contract.probation_end}}.\n",
                "Article 2 – Term: open-ended contract starting on {{contract.start_date}}, with a probation period until {{contract.probation_end}}.\n",
            ],
            'CDD' => [
                'Contrat de travail à durée déterminée', 'Fixed-term employment contract',
                "Article 2 – Durée : contrat à durée déterminée du {{contract.start_date}} au {{contract.end_date}}, renouvelable une fois dans la limite légale de deux ans.\n",
                "Article 2 – Term: fixed-term contract from {{contract.start_date}} to {{contract.end_date}}, renewable once within the two-year legal limit.\n",
            ],
            'stage' => [
                'Convention de stage', 'Internship agreement',
                "Article 2 – Durée : stage du {{contract.start_date}} au {{contract.end_date}}. Le stagiaire perçoit une gratification mensuelle (article 3) et reste soumis au règlement intérieur.\n",
                "Article 2 – Term: internship from {{contract.start_date}} to {{contract.end_date}}. The intern receives a monthly stipend (article 3) and follows the house rules.\n",
            ],
            'journalier' => [
                "Contrat de travailleur journalier", 'Daily worker contract',
                "Article 2 – Durée : engagement à la journée à compter du {{contract.start_date}}, la rémunération étant due à la fin de chaque journée ou période convenue.\n",
                "Article 2 – Term: day-to-day engagement from {{contract.start_date}}, pay being due at the end of each day or agreed period.\n",
            ],
        ];
        if ($kind === 'avenant') {
            return $fr
                ? ['title' => 'Avenant au contrat de travail', 'body' => self::PARTIES_FR."Il est convenu ce qui suit, à compter du {{change.effective_date}} :\n– fonction : {{change.from_position}} → {{change.to_position}} ;\n– salaire de base mensuel : {{change.from_salary}} FCFA → {{change.to_salary}} FCFA.\nToutes les autres clauses du contrat demeurent inchangées.".self::END_FR]
                : ['title' => 'Employment contract amendment', 'body' => self::PARTIES_EN."The parties agree as follows, effective {{change.effective_date}}:\n– position: {{change.from_position}} → {{change.to_position}};\n– monthly base salary: {{change.from_salary}} XAF → {{change.to_salary}} XAF.\nAll other terms of the contract remain unchanged.".self::END_EN];
        }
        if (! isset($bodies[$kind])) {
            return null;
        }
        [$tfr, $ten, $dfr, $den] = $bodies[$kind];

        return $fr
            ? ['title' => $tfr, 'body' => self::PARTIES_FR."Il a été convenu ce qui suit.\n\n".$job.$dfr.$pay.self::END_FR]
            : ['title' => $ten, 'body' => self::PARTIES_EN."The parties agree as follows.\n\n".$job.$den.$pay.self::END_EN];
    }
}

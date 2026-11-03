<?php

namespace App\Modules\Documents\Operations;

/**
 * D-C: editable HR letter templates (FR and EN defaults) and their placeholder whitelists.
 * Registered additively: DefaultTemplates::KINDS/get and TemplateRenderer::placeholders/sample delegate here.
 * Every letter has the employee.* and doc.* groups plus one group of its own (change, transfer, action, exit, salary).
 */
final class HrTemplates
{
    public const KINDS = ['hr_offer', 'hr_attestation', 'hr_salary_cert', 'hr_raise', 'hr_transfer', 'hr_warning', 'hr_suspension', 'hr_termination', 'hr_resignation'];

    private const EMPLOYEE = ['employee.name', 'employee.first_name', 'employee.last_name', 'employee.number', 'employee.position', 'employee.department',
        'employee.hire_date', 'employee.birth_date', 'employee.id_number', 'employee.cnps_number', 'employee.address', 'employee.base_salary',
        'employee.currency', 'employee.contract_kind', 'employee.contract_start', 'employee.contract_end', 'employee.probation_end'];

    private const EXTRA = [
        'hr_offer' => [],
        'hr_attestation' => [],
        'hr_salary_cert' => ['salary.base', 'salary.gross', 'salary.net', 'salary.period', 'salary.currency'],
        'hr_raise' => ['change.type', 'change.from_salary', 'change.to_salary', 'change.effective_date', 'change.reason', 'change.new_position', 'change.currency'],
        'hr_transfer' => ['transfer.from_hotel', 'transfer.to_hotel', 'transfer.to_department', 'transfer.to_position', 'transfer.effective_date', 'transfer.new_salary', 'transfer.reason'],
        'hr_warning' => ['action.kind', 'action.incident_date', 'action.reason', 'action.facts'],
        'hr_suspension' => ['action.kind', 'action.incident_date', 'action.reason', 'action.facts', 'action.suspension_start', 'action.suspension_end'],
        'hr_termination' => ['exit.kind', 'exit.notice_date', 'exit.last_day', 'exit.notice_worked'],
        'hr_resignation' => ['exit.kind', 'exit.notice_date', 'exit.last_day', 'exit.notice_worked'],
    ];

    private const HOTEL = ['hotel.name', 'hotel.legal_name', 'hotel.address', 'hotel.city', 'hotel.phone', 'hotel.email', 'hotel.niu', 'hotel.rccm',
        'hotel.bank_details', 'hotel.check_in_time', 'hotel.check_out_time', 'today'];

    public static function is(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** @return list<string> */
    public static function placeholders(string $kind): array
    {
        return [...self::HOTEL, ...self::EMPLOYEE, 'doc.number', ...(self::EXTRA[$kind] ?? [])];
    }

    /** @return array{title: string, body: string} */
    public static function get(string $kind, string $locale): array
    {
        return self::ALL[$kind][$locale] ?? self::ALL[$kind]['fr'];
    }

    /** Sample values for the live preview in the Templates screen. */
    public static function sample(): array
    {
        return [
            'employee.name' => 'Marie Nkoulou', 'employee.first_name' => 'Marie', 'employee.last_name' => 'Nkoulou', 'employee.number' => 'E-0042',
            'employee.position' => 'Réceptionniste', 'employee.department' => 'Réception', 'employee.hire_date' => '01/03/2024', 'employee.birth_date' => '12/05/1995',
            'employee.id_number' => '112233445', 'employee.cnps_number' => '9876543210', 'employee.address' => 'Douala', 'employee.base_salary' => '150 000',
            'employee.currency' => 'XAF', 'employee.contract_kind' => 'CDI', 'employee.contract_start' => '01/03/2024', 'employee.contract_end' => '', 'employee.probation_end' => '01/06/2024',
            'doc.number' => 'DOC-0001',
            'salary.base' => '150 000', 'salary.gross' => '175 000', 'salary.net' => '148 000', 'salary.period' => '2026-10', 'salary.currency' => 'XAF',
            'change.type' => 'raise', 'change.from_salary' => '150 000', 'change.to_salary' => '170 000', 'change.effective_date' => '01/12/2026', 'change.reason' => 'Performance', 'change.new_position' => '', 'change.currency' => 'XAF',
            'transfer.from_hotel' => 'Hôtel A', 'transfer.to_hotel' => 'Hôtel B', 'transfer.to_department' => 'Réception', 'transfer.to_position' => 'Réceptionniste', 'transfer.effective_date' => '01/12/2026', 'transfer.new_salary' => '150 000', 'transfer.reason' => 'Besoin de service',
            'action.kind' => 'Avertissement écrit', 'action.incident_date' => '10/10/2026', 'action.reason' => 'Retards répétés', 'action.facts' => 'Trois retards constatés sur la semaine.', 'action.suspension_start' => '15/10/2026', 'action.suspension_end' => '17/10/2026',
            'exit.kind' => 'Licenciement', 'exit.notice_date' => '01/11/2026', 'exit.last_day' => '30/11/2026', 'exit.notice_worked' => 'oui',
        ];
    }

    private const ALL = [
        'hr_offer' => [
            'fr' => ['title' => "Lettre d'embauche", 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}\n{{ employee.address }}\n\n# Objet : lettre d'embauche\nMadame, Monsieur {{ employee.last_name }},\n\nNous avons le plaisir de vous confirmer votre engagement au sein de {{ hotel.name }} en qualité de **{{ employee.position }}** ({{ employee.department }}), dans le cadre d'un contrat de type **{{ employee.contract_kind }}**, à compter du **{{ employee.contract_start }}**.\n\nVotre salaire de base mensuel brut est fixé à **{{ employee.base_salary }} {{ employee.currency }}**. La période d'essai prend fin le {{ employee.probation_end }}. Vous serez immatriculé(e) à la CNPS et soumis(e) au règlement intérieur de l'établissement.\n\nVeuillez nous retourner un exemplaire de la présente, daté et signé, portant la mention « Lu et approuvé ».\n\nVeuillez agréer, Madame, Monsieur, l'expression de nos salutations distinguées.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Employment letter', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}\n{{ employee.address }}\n\n# Subject: letter of employment\nDear {{ employee.first_name }},\n\nWe are pleased to confirm your appointment at {{ hotel.name }} as **{{ employee.position }}** ({{ employee.department }}), on a **{{ employee.contract_kind }}** contract starting on **{{ employee.contract_start }}**.\n\nYour gross monthly base salary is **{{ employee.base_salary }} {{ employee.currency }}**. Your probation period ends on {{ employee.probation_end }}. You will be registered with the CNPS and are bound by the establishment's internal rules.\n\nPlease return a copy of this letter, dated and signed, marked \"Read and approved\".\n\nYours sincerely,\n\nThe Management, {{ hotel.name }}"],
        ],
        'hr_attestation' => [
            'fr' => ['title' => 'Attestation de travail', 'body' => "# ATTESTATION DE TRAVAIL\nRéf. {{ doc.number }}\n\nNous soussignés, **{{ hotel.legal_name }}**, attestons que **{{ employee.name }}**, né(e) le {{ employee.birth_date }}, titulaire de la pièce d'identité n° {{ employee.id_number }} et immatriculé(e) à la CNPS sous le n° {{ employee.cnps_number }}, est employé(e) dans notre établissement depuis le **{{ employee.hire_date }}** en qualité de **{{ employee.position }}** ({{ employee.department }}), sous contrat {{ employee.contract_kind }}.\n\nCette attestation est délivrée à l'intéressé(e) pour servir et valoir ce que de droit.\n\nFait à {{ hotel.city }}, le {{ today }}\n\nLa Direction"],
            'en' => ['title' => 'Employment attestation', 'body' => "# EMPLOYMENT ATTESTATION\nRef. {{ doc.number }}\n\nWe, **{{ hotel.legal_name }}**, certify that **{{ employee.name }}**, born on {{ employee.birth_date }}, ID no. {{ employee.id_number }}, CNPS no. {{ employee.cnps_number }}, has been employed by our establishment since **{{ employee.hire_date }}** as **{{ employee.position }}** ({{ employee.department }}), on a {{ employee.contract_kind }} contract.\n\nThis attestation is issued to the employee for all legal purposes.\n\nIssued in {{ hotel.city }}, on {{ today }}\n\nThe Management"],
        ],
        'hr_salary_cert' => [
            'fr' => ['title' => 'Attestation de salaire', 'body' => "# ATTESTATION DE SALAIRE\nRéf. {{ doc.number }}\n\nNous soussignés, **{{ hotel.legal_name }}**, certifions que **{{ employee.name }}**, matricule {{ employee.number }}, employé(e) en qualité de **{{ employee.position }}** depuis le {{ employee.hire_date }}, perçoit un salaire de base mensuel brut de **{{ salary.base }} {{ salary.currency }}**.\n\nDernière paie enregistrée ({{ salary.period }}) : salaire brut {{ salary.gross }} {{ salary.currency }}, salaire net {{ salary.net }} {{ salary.currency }}.\n\nCette attestation est délivrée à la demande de l'intéressé(e), sans engagement de la part de l'employeur au-delà des montants indiqués.\n\nFait à {{ hotel.city }}, le {{ today }}\n\nLa Direction"],
            'en' => ['title' => 'Salary certificate', 'body' => "# SALARY CERTIFICATE\nRef. {{ doc.number }}\n\nWe, **{{ hotel.legal_name }}**, certify that **{{ employee.name }}**, staff no. {{ employee.number }}, employed as **{{ employee.position }}** since {{ employee.hire_date }}, receives a gross monthly base salary of **{{ salary.base }} {{ salary.currency }}**.\n\nLast recorded pay ({{ salary.period }}): gross {{ salary.gross }} {{ salary.currency }}, net {{ salary.net }} {{ salary.currency }}.\n\nThis certificate is issued at the employee's request, without commitment from the employer beyond the amounts stated.\n\nIssued in {{ hotel.city }}, on {{ today }}\n\nThe Management"],
        ],
        'hr_raise' => [
            'fr' => ['title' => "Lettre d'augmentation / promotion", 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}\n\n# Objet : révision de votre rémunération\nMadame, Monsieur {{ employee.last_name }},\n\nNous avons le plaisir de vous informer que, à compter du **{{ change.effective_date }}**, votre salaire de base mensuel brut passe de {{ change.from_salary }} {{ change.currency }} à **{{ change.to_salary }} {{ change.currency }}**.\n\nNature de la décision : {{ change.type }}. Nouveau poste : {{ change.new_position }}.\nMotif : {{ change.reason }}.\n\nNous vous remercions de votre engagement et vous encourageons à poursuivre vos efforts.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Raise / promotion letter', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}\n\n# Subject: review of your pay\nDear {{ employee.first_name }},\n\nWe are pleased to inform you that, effective **{{ change.effective_date }}**, your gross monthly base salary moves from {{ change.from_salary }} {{ change.currency }} to **{{ change.to_salary }} {{ change.currency }}**.\n\nDecision: {{ change.type }}. New position: {{ change.new_position }}.\nReason: {{ change.reason }}.\n\nThank you for your commitment; we encourage you to keep it up.\n\nThe Management, {{ hotel.name }}"],
        ],
        'hr_transfer' => [
            'fr' => ['title' => 'Lettre de mutation', 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}\n\n# Objet : mutation\nMadame, Monsieur {{ employee.last_name }},\n\nNous vous informons de votre mutation de **{{ transfer.from_hotel }}** vers **{{ transfer.to_hotel }}**, à compter du **{{ transfer.effective_date }}**, au service {{ transfer.to_department }} en qualité de **{{ transfer.to_position }}**.\n\nVotre ancienneté ({{ employee.hire_date }}) et vos droits acquis sont conservés. Salaire de base mensuel brut : {{ transfer.new_salary }} {{ employee.currency }}.\nMotif : {{ transfer.reason }}.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Transfer letter', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}\n\n# Subject: transfer\nDear {{ employee.first_name }},\n\nWe inform you of your transfer from **{{ transfer.from_hotel }}** to **{{ transfer.to_hotel }}**, effective **{{ transfer.effective_date }}**, to the {{ transfer.to_department }} department as **{{ transfer.to_position }}**.\n\nYour seniority ({{ employee.hire_date }}) and acquired rights are preserved. Gross monthly base salary: {{ transfer.new_salary }} {{ employee.currency }}.\nReason: {{ transfer.reason }}.\n\nThe Management, {{ hotel.name }}"],
        ],
        'hr_warning' => [
            'fr' => ['title' => "Lettre d'avertissement", 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Objet : {{ action.kind }}\nMadame, Monsieur {{ employee.last_name }},\n\nPar la présente, nous vous notifions un(e) **{{ action.kind }}** pour les faits survenus le {{ action.incident_date }}.\n\nMotif : {{ action.reason }}\n\nFaits constatés : {{ action.facts }}\n\nNous vous invitons à vous conformer au règlement intérieur. Toute récidive pourra donner lieu à une sanction plus sévère.\n\nVous pouvez présenter vos observations par écrit. Merci d'accuser réception en signant ci-dessous.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Warning letter', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Subject: {{ action.kind }}\nDear {{ employee.first_name }},\n\nWe hereby notify you of a **{{ action.kind }}** for the events of {{ action.incident_date }}.\n\nReason: {{ action.reason }}\n\nFacts: {{ action.facts }}\n\nPlease comply with the internal rules. Any repetition may lead to a more severe sanction.\n\nYou may submit written observations. Please acknowledge receipt by signing below.\n\nThe Management, {{ hotel.name }}"],
        ],
        'hr_suspension' => [
            'fr' => ['title' => 'Lettre de mise à pied', 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Objet : mise à pied disciplinaire\nMadame, Monsieur {{ employee.last_name }},\n\nA la suite des faits du {{ action.incident_date }}, nous vous notifions une **mise à pied** du **{{ action.suspension_start }}** au **{{ action.suspension_end }}** inclus. Cette période n'est pas rémunérée.\n\nMotif : {{ action.reason }}\n\nFaits constatés : {{ action.facts }}\n\nVous reprendrez votre service à l'issue de cette période. Merci d'accuser réception en signant ci-dessous.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Suspension letter', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Subject: disciplinary suspension\nDear {{ employee.first_name }},\n\nFollowing the events of {{ action.incident_date }}, we notify you of a **suspension** from **{{ action.suspension_start }}** to **{{ action.suspension_end }}** inclusive. This period is unpaid.\n\nReason: {{ action.reason }}\n\nFacts: {{ action.facts }}\n\nYou will resume duty at the end of this period. Please acknowledge receipt by signing below.\n\nThe Management, {{ hotel.name }}"],
        ],
        'hr_termination' => [
            'fr' => ['title' => 'Lettre de fin de contrat', 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Objet : {{ exit.kind }}\nMadame, Monsieur {{ employee.last_name }},\n\nNous vous notifions la fin de votre contrat de travail ({{ exit.kind }}). La notification est datée du {{ exit.notice_date }} et votre dernier jour de travail est le **{{ exit.last_day }}**. Préavis effectué : {{ exit.notice_worked }}.\n\nVotre solde de tout compte, votre certificat de travail et votre attestation CNPS vous seront remis à votre départ, après restitution des biens de l'établissement.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Termination letter', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Subject: {{ exit.kind }}\nDear {{ employee.first_name }},\n\nWe notify you of the end of your employment contract ({{ exit.kind }}). The notification is dated {{ exit.notice_date }} and your last working day is **{{ exit.last_day }}**. Notice worked: {{ exit.notice_worked }}.\n\nYour final settlement, work certificate and CNPS attestation will be handed over at your departure, once the establishment's property has been returned.\n\nThe Management, {{ hotel.name }}"],
        ],
        'hr_resignation' => [
            'fr' => ['title' => 'Acceptation de démission', 'body' => "{{ hotel.city }}, le {{ today }}\nRéf. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Objet : acceptation de votre démission\nMadame, Monsieur {{ employee.last_name }},\n\nNous accusons réception de votre démission notifiée le {{ exit.notice_date }} et en prenons acte. Votre dernier jour de travail sera le **{{ exit.last_day }}**. Préavis effectué : {{ exit.notice_worked }}.\n\nVotre solde de tout compte et votre certificat de travail vous seront remis à votre départ, après restitution des biens de l'établissement.\n\nNous vous remercions pour votre collaboration et vous souhaitons plein succès.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Resignation acceptance', 'body' => "{{ hotel.city }}, {{ today }}\nRef. {{ doc.number }}\n\n{{ employee.name }}, {{ employee.position }}\n\n# Subject: acceptance of your resignation\nDear {{ employee.first_name }},\n\nWe acknowledge your resignation notified on {{ exit.notice_date }} and accept it. Your last working day will be **{{ exit.last_day }}**. Notice worked: {{ exit.notice_worked }}.\n\nYour final settlement and work certificate will be handed over at your departure, once the establishment's property has been returned.\n\nThank you for your work with us; we wish you every success.\n\nThe Management, {{ hotel.name }}"],
        ],
    ];
}

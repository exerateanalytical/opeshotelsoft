<?php

namespace App\Modules\Documents\Operations;

use App\Modules\Documents\Application\TemplateRenderer;
use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\HR\Domain\Payslip;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Domain\Lifecycle\CompensationChange;
use App\Modules\HR\Domain\Lifecycle\Transfer;
use App\Modules\People\Domain\DisciplinaryAction;
use App\Modules\People\Domain\ExitCase;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;

/**
 * D-C HR documents: rows 51 to 58, 60, 61, 62. Letters come from editable templates (TemplateRenderer, whitelisted placeholders),
 * so a hotel can reword them in the Templates screen; the rest are fixed-layout sheets.
 */
class HrSheets
{
    use SheetHelpers;

    private const CONTRACT = ['permanent' => 'CDI', 'cdi' => 'CDI', 'fixed_term' => 'CDD', 'cdd' => 'CDD', 'internship' => 'Stage / Internship', 'day_worker' => 'Journalier / Day worker'];

    private const ACTION = [
        'verbal_warning' => ['Avertissement verbal', 'Verbal warning'], 'written_warning' => ['Avertissement écrit', 'Written warning'], 'suspension' => ['Mise à pied', 'Suspension'],
    ];

    private const EXIT = ['resignation' => ['Démission', 'Resignation'], 'termination' => ['Licenciement', 'Termination'], 'end_of_contract' => ['Fin de contrat', 'End of contract']];

    public string $suffix = '';

    public function __construct(private HrService $hr) {}

    private function employee(int $id, bool $unscoped = false): Employee
    {
        $q = $unscoped ? Employee::withoutGlobalScopes() : Employee::query();

        return $q->findOrFail($id);
    }

    /** ?lang, then the employee's language, then the hotel locale. */
    public function lang(?string $explicit, ?Employee $e, string $hotelLang): string
    {
        if (in_array($explicit, ['fr', 'en'], true)) {
            return $explicit;
        }
        $l = $e?->payslip_language;

        return in_array($l, ['fr', 'en'], true) ? $l : $hotelLang;
    }

    private function positionTitle(?int $id): ?string
    {
        return $id ? Position::withoutGlobalScopes()->whereKey($id)->value('title') : null;
    }

    private function employeeValues(Employee $e, string $number): array
    {
        $c = $e->contracts()->first();
        $kind = $c ? (self::CONTRACT[strtolower((string) $c->kind)] ?? $c->kind) : '';
        $v = TemplateRenderer::values($this->hotel());

        return $v + [
            'employee.name' => $e->full_name, 'employee.first_name' => $e->first_name, 'employee.last_name' => $e->last_name, 'employee.number' => $e->number,
            'employee.position' => (string) $this->positionTitle($e->position_id), 'employee.department' => (string) ($e->department_id ? Department::withoutGlobalScopes()->whereKey($e->department_id)->value('name') : ''),
            'employee.hire_date' => $this->date($e->hire_date), 'employee.birth_date' => $this->date($e->date_of_birth), 'employee.id_number' => (string) $e->id_number,
            'employee.cnps_number' => (string) $e->cnps_number, 'employee.address' => (string) $e->address, 'employee.base_salary' => $this->money($c->base_salary ?? $e->base_salary),
            'employee.currency' => 'XAF', 'employee.contract_kind' => (string) $kind, 'employee.contract_start' => $this->date($c?->start_date ?? $e->hire_date),
            'employee.contract_end' => $this->date($c?->end_date), 'employee.probation_end' => $this->date($c?->probation_end), 'doc.number' => $number,
        ];
    }

    /** A letter built from a template. @param array<string,string> $extra values of the kind's own placeholder group */
    private function letter(string $docType, string $tplKind, string $lang, Employee $e, string $number, int $sourceId, array $extra, bool $ack = false): array
    {
        $number .= $this->suffix;
        $values = $extra + $this->employeeValues($e, $number);
        $r = TemplateRenderer::renderFor($tplKind, $lang, $values);

        return [
            'type' => $docType, 'view' => 'documents::operations.letter', 'size' => 'a4', 'lang' => $lang, 'number' => $number, 'source_type' => $docType, 'source_id' => $sourceId,
            'title' => $r['title'], 'versioned' => true,
            'data' => ['docTitle' => $r['title'], 'docHtml' => $r['html'], 'ack' => $ack, 'employee_id' => $e->id],
        ];
    }

    // ---------- templated letters ----------

    public function offerLetter(int $id, ?string $lang, string $hl): array
    {
        $e = $this->employee($id);

        return $this->letter('offer_letter', 'hr_offer', $this->lang($lang, $e, $hl), $e, 'LE-'.$e->number, $e->id, [], true);
    }

    public function attestation(int $id, ?string $lang, string $hl): array
    {
        $e = $this->employee($id);

        return $this->letter('employment_attestation', 'hr_attestation', $this->lang($lang, $e, $hl), $e, 'AT-'.$e->number, $e->id, []);
    }

    public function salaryCertificate(int $id, ?string $lang, string $hl): array
    {
        $e = $this->employee($id);
        $slip = Payslip::where('employee_id', $e->id)->orderByDesc('id')->first();
        $period = $slip ? PayrollRun::whereKey($slip->payroll_run_id)->value('period') : '';
        $c = $e->contracts()->first();
        $extra = ['salary.base' => $this->money($c->base_salary ?? $e->base_salary), 'salary.gross' => $slip ? $this->money($slip->gross) : '-', 'salary.net' => $slip ? $this->money($slip->net) : '-',
            'salary.period' => (string) $period, 'salary.currency' => 'XAF'];

        return $this->letter('salary_certificate', 'hr_salary_cert', $this->lang($lang, $e, $hl), $e, 'AS-'.$e->number, $e->id, $extra);
    }

    public function raiseLetter(int $id, ?string $lang, string $hl): array
    {
        $c = CompensationChange::findOrFail($id);
        if (! in_array($c->status, ['approved', 'applied'], true)) {
            throw new DomainException('Only an approved raise or promotion has a letter.', 'raise-not-approved');
        }
        $e = $this->employee($c->employee_id);
        $lang = $this->lang($lang, $e, $hl);
        $extra = ['change.type' => $c->type === 'promotion' ? ($lang === 'en' ? 'Promotion' : 'Promotion') : ($lang === 'en' ? 'Raise' : 'Augmentation'),
            'change.from_salary' => $this->money($c->from_salary), 'change.to_salary' => $this->money($c->to_salary), 'change.effective_date' => $this->date($c->effective_date),
            'change.reason' => (string) $c->reason, 'change.new_position' => (string) ($c->to_position_id ? $this->positionTitle($c->to_position_id) : $this->positionTitle($e->position_id)), 'change.currency' => 'XAF'];

        return $this->letter('raise_letter', 'hr_raise', $lang, $e, 'AUG-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT), $c->id, $extra, true);
    }

    public function transferLetter(int $id, ?string $lang, string $hl): array
    {
        $t = Transfer::findOrFail($id); // group-scoped: only this group's transfers are visible
        if (! in_array($t->status, ['approved', 'completed'], true)) {
            throw new DomainException('Only an approved transfer has a letter.', 'transfer-not-approved');
        }
        $e = $this->employee($t->employee_id, true);
        $lang = $this->lang($lang, $e, $hl);
        $hn = fn ($hid) => (string) Hotel::withoutGlobalScopes()->whereKey($hid)->value('name');
        $dept = $t->to_department_id ? Department::withoutGlobalScopes()->whereKey($t->to_department_id)->value('name') : $e->department_id;
        $extra = ['transfer.from_hotel' => $hn($t->from_hotel_id), 'transfer.to_hotel' => $hn($t->to_hotel_id), 'transfer.to_department' => (string) ($t->to_department_id ? $dept : ''),
            'transfer.to_position' => (string) $this->positionTitle($t->to_position_id), 'transfer.effective_date' => $this->date($t->effective_date),
            'transfer.new_salary' => $this->money($t->new_base_salary ?: $e->base_salary), 'transfer.reason' => (string) $t->reason];

        return $this->letter('transfer_letter', 'hr_transfer', $lang, $e, 'MUT-'.str_pad((string) $t->id, 5, '0', STR_PAD_LEFT), $t->id, $extra, true);
    }

    public function warningLetter(int $id, ?string $lang, string $hl): array
    {
        $a = DisciplinaryAction::findOrFail($id);
        if ($a->status === 'withdrawn') {
            throw new DomainException('A withdrawn sanction has no letter.', 'sanction-withdrawn');
        }
        $e = $this->employee($a->employee_id);
        $lang = $this->lang($lang, $e, $hl);
        $suspension = $a->kind === 'suspension';
        $extra = ['action.kind' => self::ACTION[$a->kind][$lang === 'en' ? 1 : 0] ?? $a->kind, 'action.incident_date' => $this->date($a->incident_date), 'action.reason' => (string) $a->reason,
            'action.facts' => (string) $a->facts, 'action.suspension_start' => $this->date($a->suspension_start), 'action.suspension_end' => $this->date($a->suspension_end)];

        return $this->letter('warning_letter', $suspension ? 'hr_suspension' : 'hr_warning', $lang, $e, ($suspension ? 'MAP-' : 'AVT-').str_pad((string) $a->id, 5, '0', STR_PAD_LEFT), $a->id, $extra, true);
    }

    public function terminationLetter(int $id, ?string $lang, string $hl): array
    {
        $x = ExitCase::findOrFail($id);
        $e = $this->employee($x->employee_id);
        $lang = $this->lang($lang, $e, $hl);
        $yes = $x->notice_worked ? ($lang === 'en' ? 'yes' : 'oui') : ($lang === 'en' ? 'no' : 'non');
        $extra = ['exit.kind' => self::EXIT[$x->kind][$lang === 'en' ? 1 : 0] ?? $x->kind, 'exit.notice_date' => $this->date($x->notice_date), 'exit.last_day' => $this->date($x->last_day), 'exit.notice_worked' => $yes];
        $resign = $x->kind === 'resignation';

        return $this->letter('termination_letter', $resign ? 'hr_resignation' : 'hr_termination', $lang, $e, ($resign ? 'DEM-' : 'FIN-').str_pad((string) $x->id, 5, '0', STR_PAD_LEFT), $x->id, $extra, true);
    }

    // ---------- fixed layouts ----------

    /** Row 58: final settlement statement from People's exit settlement. Always flagged as needing payroll-expert review. */
    public function settlement(int $id, ?string $lang, string $hl): array
    {
        $x = ExitCase::findOrFail($id);
        $s = $x->settlement;
        if (! is_array($s) || empty($s['lines'])) {
            throw new DomainException('This exit has no settlement calculation yet.', 'no-settlement');
        }
        $e = $this->employee($x->employee_id);
        $en = $this->lang($lang, $e, $hl) === 'en';
        $labels = ['notice_indemnity' => ['Indemnité compensatrice de préavis', 'Indemnity in lieu of notice'], 'notice_not_worked' => ['Préavis non effectué (à déduire)', 'Notice not worked (deduction)'],
            'leave_indemnity' => ['Indemnité de congés payés', 'Paid leave indemnity'], 'severance' => ['Indemnité de licenciement', 'Severance pay'], 'final_month_salary' => ['Salaire du dernier mois', 'Final month salary']];
        $rows = collect($s['lines'])->map(fn ($l) => ['label' => ($labels[$l['code']] ?? [$l['code'], $l['code']])[$en ? 1 : 0], 'basis' => $l['basis'] ?? '', 'amount' => (int) $l['amount']])->all();
        $total = (int) ($s['total'] ?? $x->settlement_total);
        $sheet = [
            'title' => ['Solde de tout compte', 'Final settlement statement'],
            'status_note' => ['Calcul simplifié : à faire valider par un expert paie avant tout paiement.', 'Simplified calculation: must be reviewed by a payroll expert before any payment.'],
            'meta' => [
                ['Employé', 'Employee', $e->full_name], ['Matricule', 'Staff no.', $e->number], ['Fonction', 'Position', $this->positionTitle($e->position_id)],
                ['Motif de sortie', 'Exit type', self::EXIT[$x->kind][$en ? 1 : 0] ?? $x->kind], ['Date d\'embauche', 'Hire date', $this->date($e->hire_date)], ['Dernier jour', 'Last day', $this->date($x->last_day)],
                ['Ancienneté (années)', 'Seniority (years)', (string) ($s['seniority_years'] ?? '')], ['Salaire mensuel de référence', 'Reference monthly salary', $this->money($s['monthly_salary'] ?? 0)],
            ],
            'blocks' => [['type' => 'table', 'cols' => [self::col('label', 'Élément', 'Item'), self::col('basis', 'Base de calcul', 'Basis'), self::col('amount', 'Montant (brut)', 'Amount (gross)', 'money')],
                'rows' => $rows, 'totals' => [['Total net à payer (avant CNPS et impôt)', 'Total due (before CNPS and tax)', $total]]]],
            'note' => ['Montants bruts, avant retenues CNPS et impôt. Estimation établie selon le Code du travail du Cameroun (calcul simplifié, non validé) ; la convention collective applicable peut modifier les montants. Je reconnais avoir reçu les sommes ci-dessus pour solde de tout compte.',
                'Gross amounts, before CNPS and tax. Estimate under the Cameroon Labour Code (simplified, not validated); the applicable collective agreement may change the amounts. I acknowledge receipt of the sums above in full settlement.'],
            'sign' => [['L\'employé (« reçu pour solde de tout compte »)', 'Employee ("received in full settlement")'], ['La Direction', 'Management']],
        ];

        return $this->result('final_settlement', $sheet, 'STC-'.($x->certificate_number ?: str_pad((string) $x->id, 5, '0', STR_PAD_LEFT)), $x->id, ['lang' => $en ? 'en' : 'fr']);
    }

    /** Row 60: leave approval slip. */
    public function leaveSlip(int $id, ?string $lang, string $hl): array
    {
        $l = LeaveRequest::findOrFail($id);
        if ($l->status !== 'approved') {
            throw new DomainException('Only an approved leave has an approval slip.', 'leave-not-approved');
        }
        $e = $this->employee($l->employee_id);
        $en = $this->lang($lang, $e, $hl) === 'en';
        $type = LeaveType::withoutGlobalScopes()->find($l->leave_type_id);
        $sheet = [
            'title' => ['Bon d\'approbation de congé', 'Leave approval slip'],
            'meta' => [
                ['Employé', 'Employee', $e->full_name], ['Matricule', 'Staff no.', $e->number], ['Service', 'Department', $e->department_id ? Department::withoutGlobalScopes()->whereKey($e->department_id)->value('name') : null],
                ['Type de congé', 'Leave type', $type?->name], ['Du', 'From', $this->date($l->start_date)], ['Au', 'To', $this->date($l->end_date)], ['Nombre de jours', 'Days', (string) (float) $l->days],
                ['Motif', 'Reason', $l->reason], ['Approuvé par', 'Approved by', $this->userName($l->decided_by)], ['Solde de congés annuels restant', 'Annual leave balance left', (string) $this->hr->leaveBalance($e)],
            ],
            'note' => ['Ce congé a été approuvé. Reprise du travail le lendemain de la date de fin.', 'This leave has been approved. Return to work the day after the end date.'],
            'sign' => [['L\'employé', 'Employee'], ['Chef de service', 'Head of department'], ['Ressources humaines', 'Human resources']],
        ];

        return $this->result('leave_slip', $sheet, 'CG-'.str_pad((string) $l->id, 5, '0', STR_PAD_LEFT), $l->id, ['lang' => $en ? 'en' : 'fr']);
    }

    /** Row 61: staff ID card (ID-1) with the employee photo. */
    public function idCard(int $id, ?string $lang, string $hl): array
    {
        $e = $this->employee($id);
        $lang = $this->lang($lang, $e, $hl);

        return [
            'type' => 'staff_id_card', 'view' => 'documents::operations.idcard', 'size' => 'id1', 'lang' => $lang, 'number' => 'BADGE-'.$e->number.$this->suffix, 'source_type' => 'staff_id_card', 'source_id' => $e->id,
            'title' => ['Carte de service', 'Staff card'], 'versioned' => true,
            'data' => ['card' => ['name' => $e->full_name, 'number' => $e->number, 'position' => (string) $this->positionTitle($e->position_id),
                'department' => (string) ($e->department_id ? Department::withoutGlobalScopes()->whereKey($e->department_id)->value('name') : ''),
                'hotel' => (string) $this->hotel()?->name, 'photo' => $e->photoDataUri(), 'valid_until' => now()->addYears(2)->format('m/Y')]],
        ];
    }

    /** Row 62: payroll run journal (A4 landscape). The CNPS declaration data stays in the accounting payroll filings. */
    public function payrollJournal(int $id, ?string $lang, string $hl): array
    {
        $run = PayrollRun::findOrFail($id);
        $slips = Payslip::where('payroll_run_id', $run->id)->orderBy('id')->get();
        $emps = Employee::whereIn('id', $slips->pluck('employee_id'))->get()->keyBy('id');
        $rows = $slips->map(fn ($p) => ['number' => $emps[$p->employee_id]->number ?? '', 'name' => $emps[$p->employee_id]->full_name ?? '', 'cnps' => $emps[$p->employee_id]->cnps_number ?? '',
            'gross' => (int) $p->gross, 'taxable' => (int) $p->taxable, 'emp_social' => (int) $p->employee_social, 'tax' => (int) $p->taxes_withheld,
            'adv' => (int) $p->advances_recovered, 'net' => (int) $p->net, 'er_social' => (int) $p->employer_social])->all();
        $foot = ['name' => 'TOTAL', 'gross' => (int) $slips->sum('gross'), 'taxable' => (int) $slips->sum('taxable'), 'emp_social' => (int) $slips->sum('employee_social'), 'tax' => (int) $slips->sum('taxes_withheld'),
            'adv' => (int) $slips->sum('advances_recovered'), 'net' => (int) $slips->sum('net'), 'er_social' => (int) $slips->sum('employer_social')];
        $sheet = [
            'title' => ['Journal de paie', 'Payroll journal'],
            'meta' => [['Période', 'Period', $run->period], ['Statut', 'Status', $run->status], ['Employés', 'Employees', (string) count($rows)], ['Préparé par', 'Prepared by', $this->userName($run->prepared_by)],
                ['Approuvé par', 'Approved by', $this->userName($run->approved_by)]],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('number', 'Matricule', 'Staff no.'), self::col('name', 'Nom', 'Name'), self::col('cnps', 'N° CNPS', 'CNPS no.'), self::col('gross', 'Brut', 'Gross', 'money'),
                self::col('taxable', 'Imposable', 'Taxable', 'money'), self::col('emp_social', 'CNPS salarié', 'Employee CNPS', 'money'), self::col('tax', 'Impôts retenus', 'Tax withheld', 'money'),
                self::col('adv', 'Avances', 'Advances', 'money'), self::col('net', 'Net à payer', 'Net pay', 'money'), self::col('er_social', 'Charges patronales', 'Employer charges', 'money'),
            ], 'rows' => $rows, 'foot' => $foot]],
            'note' => ['Document confidentiel. Les données de la déclaration CNPS sont disponibles en Excel dans les déclarations de paie de la comptabilité.', 'Confidential. CNPS declaration data is available as Excel in the accounting payroll filings.'],
            'sign' => [['Préparé par (paie)', 'Prepared by (payroll)'], ['Approuvé par (direction)', 'Approved by (management)']],
        ];

        return $this->result('payroll_journal', $sheet, 'PJ-'.str_replace('-', '', (string) $run->period).'-'.$run->id, $run->id, ['size' => 'a4-landscape', 'lang' => $this->lang($lang, null, $hl)]);
    }
}

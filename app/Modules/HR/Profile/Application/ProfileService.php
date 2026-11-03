<?php

namespace App\Modules\HR\Profile\Application;

use App\Models\User;
use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\Payslip;
use App\Modules\HR\Domain\SalaryAdvance;
use App\Modules\HR\Domain\Shift;
use App\Modules\HR\Domain\Lifecycle\CompensationChange;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Domain\Lifecycle\PayElement;
use App\Modules\HR\Domain\Lifecycle\SalaryHistory;
use App\Modules\HR\Domain\Lifecycle\Transfer;
use App\Modules\HR\Talent\Domain\Certification;
use App\Modules\HR\Talent\Domain\EmployeeSkill;
use App\Modules\HR\Talent\Domain\TrainingAttendance;
use App\Modules\HR\Workforce\Domain\Anomaly;
use App\Modules\HR\Workforce\Domain\LeaveLedger;
use App\Modules\HR\Workforce\Domain\RosterShift;
use App\Modules\People\Domain\DisciplinaryAction;
use App\Modules\People\Domain\Feedback;
use App\Modules\People\Domain\Objective;
use App\Modules\People\Domain\Review;
use App\Modules\People\Domain\Task;
use App\Modules\Payments\Domain\PayoutItem;
use App\Modules\Stock\Operations\Domain\IssueRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H-C employee 360 profile. Read-only aggregation over HR, Workforce, Talent, People, Payments and Stock Operations
 * domain data. Every figure is read from the owning table (salary history, payslips, advances, payout items): no salary
 * arithmetic happens here beyond summing stored payslip columns for the year-to-date and owing totals.
 * The caller's permissions decide which sections exist at all; a hidden section is absent, not an error.
 */
class ProfileService
{
    public const SECTIONS = ['overview', 'employment', 'pay', 'time', 'documents', 'growth', 'conduct', 'assets', 'timeline'];

    /** document engine type => ops kind (GET /api/v1/documents/ops/{kind}/{source_id}) */
    private const LETTER_KINDS = [
        'offer_letter' => 'offer-letter', 'employment_attestation' => 'employment-attestation', 'salary_certificate' => 'salary-certificate',
        'raise_letter' => 'raise-letter', 'transfer_letter' => 'transfer-letter', 'warning_letter' => 'warning-letter',
        'termination_letter' => 'termination-letter', 'staff_id_card' => 'staff-id-card',
    ];

    public function __construct(private HrService $hr) {}

    // ---- Permissions ----

    public function canViewCore(User $u): bool
    {
        return $u->can('hr.view') || $u->can('hr.manage') || $u->can('payroll.manage');
    }

    public function canPay(User $u): bool
    {
        return $u->can('payroll.view') || $u->can('payroll.manage');
    }

    private function canTalent(User $u): bool
    {
        return $u->can('talent.view') || $u->can('hr.manage');
    }

    private function canPerformance(User $u): bool
    {
        return $u->can('people.view') || $u->can('people.manage');
    }

    private function canDiscipline(User $u): bool
    {
        return $u->can('people.discipline');
    }

    private function canTasks(User $u): bool
    {
        return $u->can('people.view') || $u->can('people.tasks');
    }

    /** @return string[] section keys the caller may see */
    public function sectionsFor(User $u): array
    {
        if (! $this->canViewCore($u)) {
            return [];
        }
        $out = ['overview', 'employment'];
        if ($this->canPay($u)) {
            $out[] = 'pay';
        }
        array_push($out, 'time', 'documents');
        if ($this->canTalent($u) || $this->canPerformance($u)) {
            $out[] = 'growth';
        }
        if ($this->canDiscipline($u) || $this->canTasks($u)) {
            $out[] = 'conduct';
        }
        $out[] = 'assets';
        $out[] = 'timeline';

        return $out;
    }

    // ---- Header: cheap, always returned ----

    public function header(Employee $e, User $u): array
    {
        $e->loadMissing('department:id,name', 'manager:id,first_name,last_name');

        return [
            'employee' => [
                'id' => $e->id, 'number' => $e->number, 'first_name' => $e->first_name, 'last_name' => $e->last_name, 'full_name' => $e->full_name,
                'status' => $e->status, 'photo_url' => $e->photo_url, 'hotel' => $this->hotelName($e->hotel_id),
                'department' => $e->department?->name, 'position' => $this->positionTitle($e->position_id),
                'manager' => $e->manager ? ['id' => $e->manager->id, 'name' => $e->manager->full_name] : null,
            ],
            'sections' => $this->sectionsFor($u),
            'actions' => $this->actions($e, $u),
        ];
    }

    /** Permission-aware action menu. Each entry names the existing endpoint or screen it uses. */
    public function actions(Employee $e, User $u): array
    {
        $manage = $u->can('hr.manage');
        $life = $u->can('hr.lifecycle.manage') || $manage;
        $active = $e->status !== 'terminated';
        $id = $e->id;
        $a = [];
        $add = function (string $key, bool $ok, array $extra) use (&$a) {
            if ($ok) {
                $a[] = ['key' => $key] + $extra;
            }
        };
        $add('edit', $manage && $active, ['href' => "/hr?employee=$id", 'api' => "PATCH hr/employees/$id"]);
        $add('raise', $life && $active, ['href' => "/hr/lifecycle?employee=$id&tab=raises", 'api' => "POST hr/employees/$id/compensation-changes"]);
        $add('transfer', $life && $active, ['href' => "/hr/lifecycle?employee=$id&tab=transfers", 'api' => "POST hr/employees/$id/transfers"]);
        $add('bonus', $life && $active, ['href' => "/hr/lifecycle?employee=$id&tab=bonuses", 'api' => "POST hr/employees/$id/pay-elements"]);
        $add('contract', $life, ['href' => "/hr/lifecycle?employee=$id&tab=contracts", 'api' => "POST hr/employees/$id/econtracts"]);
        $add('letter', $manage, ['print' => "employment-attestation/$id"]);
        $add('id_card', $manage || $u->can('hr.view'), ['print' => "staff-id-card/$id"]);
        $add('advance', $u->can('payroll.manage') && $active, ['href' => "/hr?employee=$id", 'api' => "POST hr/employees/$id/advances"]);
        $add('terminate', $manage && $active, ['href' => "/hr?employee=$id", 'api' => "POST hr/employees/$id/terminate"]);

        return $a;
    }

    public function section(string $key, Employee $e, User $u): array
    {
        return match ($key) {
            'overview' => $this->overview($e, $u),
            'employment' => $this->employment($e, $u),
            'pay' => $this->pay($e),
            'time' => $this->time($e),
            'documents' => $this->documents($e, $u),
            'growth' => $this->growth($e, $u),
            'conduct' => $this->conduct($e, $u),
            'assets' => $this->assets($e),
            'timeline' => $this->timeline($e, $u),
            default => abort(404),
        };
    }

    // ---- Overview ----

    public function overview(Employee $e, User $u): array
    {
        $e->loadMissing('department:id,name', 'manager:id,first_name,last_name');
        $end = $e->termination_date ?? now();
        $months = (int) $e->hire_date->diffInMonths($end);
        $canPay = $this->canPay($u);
        $payDest = $e->payment_method === 'mobile_money' ? $e->momo_number : ($e->payment_method === 'bank' ? $e->bank_account : 'cash');

        $checks = [
            'photo' => (bool) $e->photo_path, 'phone' => (bool) $e->phone, 'email' => (bool) $e->email, 'address' => (bool) $e->address,
            'date_of_birth' => (bool) $e->date_of_birth, 'id_number' => (bool) $e->id_number, 'cnps_number' => (bool) $e->cnps_number,
            'tax_number' => (bool) $e->tax_number, 'payment_destination' => (bool) $payDest, 'department' => (bool) $e->department_id,
            'position' => (bool) $e->position_id, 'manager' => (bool) $e->reports_to,
            'contract' => Contract::where('employee_id', $e->id)->exists(),
        ];
        $missing = array_keys(array_filter($checks, fn ($ok) => ! $ok));

        return [
            'number' => $e->number, 'status' => $e->status, 'photo_url' => $e->photo_url,
            'hotel' => $this->hotelName($e->hotel_id), 'department' => $e->department?->name, 'position' => $this->positionTitle($e->position_id),
            'manager' => $e->manager ? ['id' => $e->manager->id, 'name' => $e->manager->full_name] : null,
            'hire_date' => $e->hire_date->toDateString(), 'termination_date' => $e->termination_date?->toDateString(), 'termination_reason' => $e->termination_reason,
            'tenure' => ['years' => intdiv($months, 12), 'months' => $months % 12],
            'gender' => $e->gender, 'date_of_birth' => $e->date_of_birth?->toDateString(), 'nationality' => $e->nationality,
            'marital_status' => $e->marital_status, 'children' => $e->children,
            'phone' => $e->phone, 'email' => $e->email, 'address' => $e->address,
            'emergency_contact' => null, // no emergency contact is stored in the HR schema yet
            'id_number' => $e->id_number, 'cnps_number' => $e->cnps_number, 'tax_number' => $e->tax_number,
            'payment_method' => $e->payment_method,
            // Bank and Mobile Money details are payroll data.
            'bank_name' => $canPay ? $e->bank_name : null, 'bank_account' => $canPay ? $e->bank_account : null, 'momo_number' => $canPay ? $e->momo_number : null,
            'completeness' => ['percent' => (int) round(100 * (count($checks) - count($missing)) / count($checks)), 'missing' => $missing, 'checks' => $checks],
        ];
    }

    // ---- Employment ----

    public function employment(Employee $e, User $u): array
    {
        $canPay = $this->canPay($u);
        $contracts = Contract::where('employee_id', $e->id)->orderByDesc('start_date')->orderByDesc('id')->get()->map(fn (Contract $c) => [
            'id' => $c->id, 'kind' => $c->kind, 'start_date' => $c->start_date?->toDateString(), 'end_date' => $c->end_date?->toDateString(),
            'probation_end' => $c->probation_end?->toDateString(), 'status' => $c->status, 'hours_per_week' => $c->hours_per_week,
            'base_salary' => $canPay ? (int) $c->base_salary : null,
        ])->values();

        $econtracts = EContract::where('employee_id', $e->id)->orderByDesc('id')->get()->map(fn (EContract $c) => [
            'id' => $c->id, 'number' => $c->number, 'kind' => $c->kind, 'status' => $c->status, 'title' => $c->title,
            'employee_signed_at' => $c->employee_signed_at?->toIso8601String(), 'employer_signed_at' => $c->employer_signed_at?->toIso8601String(),
            'pdf_url' => '/api/v1/hr/econtracts/'.$c->id.'/pdf', 'sealed' => (bool) $c->pdf_path,
        ])->values();

        // Position history from the salary history rows (position at each effective date); salary is not exposed here.
        $hist = SalaryHistory::where('employee_id', $e->id)->orderBy('effective_date')->orderBy('id')->get();
        $positions = [];
        $last = null;
        foreach ($hist as $h) {
            if ($h->position_id !== $last) {
                $positions[] = ['effective_date' => $h->effective_date->toDateString(), 'position' => $this->positionTitle($h->position_id), 'source' => $h->source];
                $last = $h->position_id;
            }
        }

        $transfers = Transfer::where('employee_id', $e->id)->orderByDesc('effective_date')->orderByDesc('id')->get();
        $departments = $this->names('departments', $transfers->flatMap(fn ($t) => [$t->from_department_id, $t->to_department_id]));
        $hotels = $this->names('hotels', $transfers->flatMap(fn ($t) => [$t->from_hotel_id, $t->to_hotel_id]));

        return [
            'hire_date' => $e->hire_date->toDateString(),
            'probation_end' => Contract::where('employee_id', $e->id)->whereNotNull('probation_end')->orderBy('start_date')->value('probation_end'),
            'contracts' => $contracts,
            'econtracts' => $econtracts,
            'position_history' => array_reverse($positions),
            'department_history' => $transfers->where('status', 'completed')->map(fn ($t) => [
                'effective_date' => $t->effective_date->toDateString(), 'from' => $departments[$t->from_department_id] ?? null, 'to' => $departments[$t->to_department_id] ?? null,
            ])->filter(fn ($d) => $d['from'] !== $d['to'])->values(),
            'transfers' => $transfers->map(fn (Transfer $t) => [
                'id' => $t->id, 'status' => $t->status, 'effective_date' => $t->effective_date->toDateString(),
                'from_hotel' => $hotels[$t->from_hotel_id] ?? null, 'to_hotel' => $hotels[$t->to_hotel_id] ?? null,
                'from_position' => $this->positionTitle($t->from_position_id), 'to_position' => $this->positionTitle($t->to_position_id),
                'seniority_date' => $t->seniority_date?->toDateString(), 'leave_balance' => $t->leave_balance, 'reason' => $t->reason,
            ])->values(),
        ];
    }

    // ---- Pay (payroll permission only) ----

    public function pay(Employee $e): array
    {
        $users = null;
        $changes = CompensationChange::where('employee_id', $e->id)->get()->keyBy('id');
        $rows = SalaryHistory::where('employee_id', $e->id)->orderBy('effective_date')->orderBy('id')->get();
        $prev = null;
        $history = [];
        foreach ($rows as $h) {
            $change = in_array($h->source, ['raise', 'promotion'], true) ? $changes[$h->source_id] ?? null : null;
            $history[] = [
                'id' => $h->id, 'date' => $h->effective_date->toDateString(), 'source' => $h->source,
                'from' => $prev, 'to' => (int) $h->base_salary,
                'percent' => $prev !== null && $prev > 0 ? round(($h->base_salary - $prev) / $prev * 100, 1) : null,
                'reason' => $change?->reason, 'approved_by' => $change?->decided_by ? $this->userName($change->decided_by, $users) : null,
            ];
            $prev = (int) $h->base_salary;
        }

        $elements = PayElement::where('employee_id', $e->id)->where('status', 'active')->orderByDesc('id')->get()->map(fn (PayElement $p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'kind' => $p->kind, 'amount' => (int) $p->amount, 'taxable' => $p->taxable, 'cnps' => $p->cnps,
            'period' => $p->period, 'start_period' => $p->start_period, 'end_period' => $p->end_period,
        ])->values();

        $payslips = Payslip::with('run:id,period,status,paid_at')->where('employee_id', $e->id)->get()
            ->filter(fn ($p) => $p->run)->sortByDesc(fn ($p) => $p->run->period)->values();
        $slipRows = $payslips->map(fn (Payslip $p) => [
            'id' => $p->id, 'period' => $p->run->period, 'run_status' => $p->run->status, 'gross' => (int) $p->gross, 'net' => (int) $p->net,
            'employee_social' => (int) $p->employee_social, 'taxes_withheld' => (int) $p->taxes_withheld, 'advances_recovered' => (int) $p->advances_recovered,
            'deductions' => (int) $p->employee_social + (int) $p->taxes_withheld + (int) $p->advances_recovered,
            'pdf_url' => '/api/v1/documents/payslips/'.$p->id,
        ])->values();

        $year = (string) now()->year;
        $ytdSlips = $payslips->filter(fn ($p) => str_starts_with($p->run->period, $year) && in_array($p->run->status, ['approved', 'paid'], true));
        $ytd = [
            'year' => (int) $year, 'payslips' => $ytdSlips->count(),
            'gross' => (int) $ytdSlips->sum('gross'), 'net' => (int) $ytdSlips->sum('net'),
            'employee_social' => (int) $ytdSlips->sum('employee_social'), 'taxes_withheld' => (int) $ytdSlips->sum('taxes_withheld'),
            'advances_recovered' => (int) $ytdSlips->sum('advances_recovered'),
        ];

        $advances = SalaryAdvance::where('employee_id', $e->id)->orderByDesc('granted_on')->orderByDesc('id')->get();
        $payoutItems = PayoutItem::whereIn('payslip_id', $payslips->pluck('id'))->orderByDesc('id')->get()->keyBy('payslip_id');
        $unpaidSlips = $payslips->filter(fn ($p) => in_array($p->run->status, ['approved', 'paid'], true)
            && ($payoutItems[$p->id]->status ?? null) !== 'succeeded' && $p->run->status !== 'paid');
        $paid = $payoutItems->where('status', 'succeeded');
        $lastPayout = $payoutItems->sortByDesc('id')->first();

        return [
            'base_salary' => (int) $e->base_salary,
            'allowances' => collect($e->allowances ?? [])->map(fn ($a) => ['code' => $a['code'] ?? null, 'name' => $a['name'] ?? null, 'amount' => (int) ($a['amount'] ?? 0)])->values(),
            'pay_elements' => $elements,
            'salary_history' => $history,
            'payslips' => $slipRows,
            'ytd' => $ytd,
            'paid' => ['amount' => (int) $paid->sum('amount'), 'items' => $paid->count()],
            'owing' => [
                'advances_balance' => (int) $advances->where('status', 'active')->sum('balance'),
                'unpaid_net' => (int) $unpaidSlips->sum('net'),
                'unpaid_payslips' => $unpaidSlips->map(fn ($p) => ['id' => $p->id, 'period' => $p->run->period, 'net' => (int) $p->net, 'run_status' => $p->run->status])->values(),
                'payout_pending' => $payoutItems->whereIn('status', ['pending', 'sent', 'exported', 'failed'])->map(fn ($i) => ['id' => $i->id, 'amount' => (int) $i->amount, 'status' => $i->status, 'channel' => $i->channel])->values(),
                'leave_pay_due' => null, // leave pay is only computed at exit (settlement); not stored per employee
            ],
            'advances' => $advances->map(fn (SalaryAdvance $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'amount' => (int) $a->amount, 'monthly_deduction' => (int) $a->monthly_deduction,
                'balance' => (int) $a->balance, 'granted_on' => $a->granted_on, 'status' => $a->status,
            ])->values(),
            'last_payout' => $lastPayout ? [
                'status' => $lastPayout->status, 'amount' => (int) $lastPayout->amount, 'channel' => $lastPayout->channel,
                'paid_at' => $lastPayout->paid_at?->toIso8601String(), 'period' => $payslips->firstWhere('id', $lastPayout->payslip_id)?->run->period,
            ] : null,
        ];
    }

    // ---- Time ----

    public function time(Employee $e): array
    {
        $weekStart = now()->startOfWeek();
        $weekEnd = $weekStart->copy()->addWeeks(2)->subDay();
        $shifts = Shift::where('employee_id', $e->id)->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])->orderBy('date')->orderBy('starts_at')->get()
            ->map(fn ($s) => ['date' => Carbon::parse($s->date)->toDateString(), 'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5), 'role' => $s->role, 'source' => 'shift']);
        $rostered = RosterShift::where('employee_id', $e->id)->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereIn('roster_id', DB::table('workforce_rosters')->where('status', 'published')->select('id'))->orderBy('date')->orderBy('starts_at')->get()
            ->map(fn ($s) => ['date' => Carbon::parse($s->date)->toDateString(), 'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5), 'role' => $s->role, 'source' => 'roster']);
        $allShifts = $shifts->concat($rostered)->sortBy(fn ($s) => $s['date'].$s['starts_at'])->values();

        $since = now()->subDays(30)->toDateString();
        $att = Attendance::where('employee_id', $e->id)->where('date', '>=', $since)->orderByDesc('date')->get();
        $anoms = Anomaly::where('employee_id', $e->id)->where('date', '>=', $since)->get();
        $late = $anoms->where('kind', 'late');
        $missing = $att->filter(fn ($a) => $a->clock_in && ! $a->clock_out && Carbon::parse($a->date)->lt(today()));

        $overtime = DB::table('overtime_entries')->where('employee_id', $e->id)
            ->selectRaw("to_char(date, 'YYYY-MM') as month, status, sum(minutes) as minutes")->groupBy('month', 'status')->orderByDesc('month')->limit(48)->get()
            ->groupBy('month')->map(fn ($g, $m) => [
                'month' => $m, 'approved_hours' => round($g->where('status', 'approved')->sum('minutes') / 60, 1), 'pending_hours' => round($g->where('status', 'pending')->sum('minutes') / 60, 1),
            ])->values()->take(12);

        $types = DB::table('leave_types')->get()->keyBy('id');
        $ledger = LeaveLedger::where('employee_id', $e->id)->get()->groupBy('leave_type_id')->map(fn ($g, $tid) => [
            'leave_type' => $types[$tid]->name ?? null, 'days' => round($g->sum('days'), 2),
        ])->values();

        $leaves = LeaveRequest::where('employee_id', $e->id)->orderByDesc('start_date')->limit(30)->get()->map(fn (LeaveRequest $l) => [
            'id' => $l->id, 'type' => $types[$l->leave_type_id]->name ?? null, 'start_date' => Carbon::parse($l->start_date)->toDateString(),
            'end_date' => Carbon::parse($l->end_date)->toDateString(), 'days' => (float) $l->days, 'status' => $l->status, 'reason' => $l->reason,
        ])->values();

        return [
            'shifts' => $allShifts,
            'attendance' => $att->map(fn ($a) => [
                'date' => Carbon::parse($a->date)->toDateString(), 'clock_in' => $a->clock_in?->toIso8601String(), 'clock_out' => $a->clock_out?->toIso8601String(),
                'worked_minutes' => (int) $a->worked_minutes, 'overtime_minutes' => (int) $a->overtime_minutes,
            ])->values(),
            'late_count' => $late->count(), 'missing_clock_out' => $missing->map(fn ($a) => Carbon::parse($a->date)->toDateString())->values(),
            'late_dates' => $late->map(fn ($a) => $a->date->toDateString())->values(),
            'overtime_by_month' => $overtime,
            'leave_balance' => $this->hr->leaveBalance($e), 'leave_ledger' => $ledger,
            'leaves' => $leaves,
        ];
    }

    // ---- Documents ----

    public function documents(Employee $e, User $u): array
    {
        $canPay = $this->canPay($u);
        $docs = EmployeeDocument::where('employee_id', $e->id)->orderByDesc('id')->get()->map(function (EmployeeDocument $d) {
            $days = $d->expires_on ? (int) today()->diffInDays($d->expires_on, false) : null;

            return [
                'id' => $d->id, 'type' => $d->type, 'title' => $d->title, 'original_name' => $d->original_name, 'expires_on' => $d->expires_on?->toDateString(),
                'expiry' => $days === null ? 'none' : ($days < 0 ? 'expired' : ($days <= 30 ? 'soon' : 'valid')), 'days_left' => $days,
                'url' => '/api/v1/hr/documents/'.$d->id.'/download',
            ];
        })->values();

        $econtracts = EContract::where('employee_id', $e->id)->orderByDesc('id')->get()->map(fn (EContract $c) => [
            'id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'kind' => $c->kind, 'pdf_url' => '/api/v1/hr/econtracts/'.$c->id.'/pdf',
        ])->values();

        $changeIds = CompensationChange::where('employee_id', $e->id)->pluck('id');
        $transferIds = Transfer::where('employee_id', $e->id)->pluck('id');
        $warnIds = DisciplinaryAction::where('employee_id', $e->id)->pluck('id');
        $exitIds = DB::table('people_exits')->where('employee_id', $e->id)->pluck('id');
        $issued = DB::table('issued_documents')->where(function ($q) use ($e, $changeIds, $transferIds, $warnIds, $exitIds) {
            $q->where(fn ($w) => $w->whereIn('type', ['offer_letter', 'employment_attestation', 'salary_certificate', 'staff_id_card'])->where('source_id', $e->id))
                ->orWhere(fn ($w) => $w->where('type', 'raise_letter')->whereIn('source_id', $changeIds))
                ->orWhere(fn ($w) => $w->where('type', 'transfer_letter')->whereIn('source_id', $transferIds))
                ->orWhere(fn ($w) => $w->where('type', 'warning_letter')->whereIn('source_id', $warnIds))
                ->orWhere(fn ($w) => $w->where('type', 'termination_letter')->whereIn('source_id', $exitIds));
        })->when(! $canPay, fn ($q) => $q->whereNotIn('type', ['salary_certificate', 'raise_letter']))
            ->orderByDesc('issued_at')->limit(50)->get()->map(fn ($d) => [
                'id' => $d->id, 'type' => $d->type, 'number' => $d->number, 'status' => $d->status, 'issued_at' => Carbon::parse($d->issued_at)->toIso8601String(),
                'print' => isset(self::LETTER_KINDS[$d->type]) ? self::LETTER_KINDS[$d->type].'/'.$d->source_id : null,
            ])->values();

        return [
            'documents' => $docs, 'econtracts' => $econtracts, 'issued' => $issued,
            'id_card_print' => "staff-id-card/{$e->id}",
            'can_upload' => $u->can('hr.manage'), 'upload_api' => "hr/employees/{$e->id}/documents",
        ];
    }

    // ---- Growth ----

    public function growth(Employee $e, User $u): array
    {
        $out = ['skills' => null, 'certificates' => null, 'training' => null, 'reviews' => null, 'objectives' => null, 'feedback' => null];
        if ($this->canTalent($u)) {
            $skills = DB::table('tal_skills')->get()->keyBy('id');
            $out['skills'] = EmployeeSkill::where('employee_id', $e->id)->get()->map(fn ($s) => [
                'skill' => $skills[$s->skill_id]->name ?? null, 'category' => $skills[$s->skill_id]->category ?? null, 'level' => (int) $s->level, 'assessed_on' => $s->assessed_on ? Carbon::parse($s->assessed_on)->toDateString() : null,
            ])->values();
            $types = DB::table('tal_cert_types')->get()->keyBy('id');
            $out['certificates'] = Certification::where('employee_id', $e->id)->orderBy('expires_on')->get()->map(function ($c) use ($types) {
                $days = $c->expires_on ? (int) today()->diffInDays($c->expires_on, false) : null;

                return ['id' => $c->id, 'name' => $types[$c->cert_type_id]->name ?? null, 'mandatory' => (bool) ($types[$c->cert_type_id]->mandatory ?? false),
                    'issued_on' => $c->issued_on?->toDateString(), 'expires_on' => $c->expires_on?->toDateString(), 'reference' => $c->reference, 'issuer' => $c->issuer,
                    'expiry' => $days === null ? 'none' : ($days < 0 ? 'expired' : ($days <= 60 ? 'soon' : 'valid')), 'days_left' => $days];
            })->values();
            $sessions = DB::table('tal_training_sessions')->get()->keyBy('id');
            $courses = DB::table('tal_courses')->get()->keyBy('id');
            $out['training'] = TrainingAttendance::where('employee_id', $e->id)->orderByDesc('id')->get()->map(fn ($a) => [
                'course' => $courses[$sessions[$a->session_id]->course_id ?? 0]->title ?? null,
                'starts_on' => isset($sessions[$a->session_id]) ? Carbon::parse($sessions[$a->session_id]->starts_on)->toDateString() : null,
                'attendance' => $a->attendance, 'result' => $a->result, 'score' => $a->score,
            ])->values();
        }
        if ($this->canPerformance($u)) {
            $cycles = DB::table('people_review_cycles')->get()->keyBy('id');
            $reviews = Review::where('employee_id', $e->id)->orderByDesc('id')->get();
            $out['reviews'] = $reviews->map(fn ($r) => [
                'id' => $r->id, 'cycle' => $cycles[$r->cycle_id]->name ?? null, 'status' => $r->status, 'self_rating' => $r->self_rating,
                'manager_rating' => $r->manager_rating, 'calibrated_rating' => $r->calibrated_rating, 'scale' => $cycles[$r->cycle_id]->scale ?? null,
            ])->values();
            $out['objectives'] = Objective::whereIn('review_id', $reviews->pluck('id'))->get()->map(fn ($o) => [
                'id' => $o->id, 'title' => $o->title, 'weight' => $o->weight, 'self_score' => $o->self_score, 'manager_score' => $o->manager_score,
            ])->values();
            $out['feedback'] = Feedback::where('employee_id', $e->id)->orderByDesc('received_on')->limit(30)->get()->map(fn ($f) => [
                'id' => $f->id, 'source' => $f->source, 'rating' => $f->rating, 'sentiment' => $f->sentiment, 'comment' => $f->comment,
                'received_on' => $f->received_on ? Carbon::parse($f->received_on)->toDateString() : null,
            ])->values();
        }

        return $out;
    }

    // ---- Conduct ----

    public function conduct(Employee $e, User $u): array
    {
        $out = ['warnings' => null, 'tasks' => null];
        if ($this->canDiscipline($u)) {
            $out['warnings'] = DisciplinaryAction::where('employee_id', $e->id)->orderByDesc('incident_date')->get()->map(fn ($d) => [
                'id' => $d->id, 'kind' => $d->kind, 'incident_date' => Carbon::parse($d->incident_date)->toDateString(), 'reason' => $d->reason, 'status' => $d->status,
                'suspension_start' => $d->suspension_start ? Carbon::parse($d->suspension_start)->toDateString() : null,
                'suspension_end' => $d->suspension_end ? Carbon::parse($d->suspension_end)->toDateString() : null,
                'acknowledged_at' => $d->acknowledged_at ? Carbon::parse($d->acknowledged_at)->toIso8601String() : null,
            ])->values();
        }
        if ($this->canTasks($u)) {
            $out['tasks'] = Task::where('assignee_employee_id', $e->id)->orderByRaw("case when status in ('done','completed','cancelled') then 1 else 0 end")->orderBy('due_at')->limit(50)->get()->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title, 'priority' => $t->priority, 'status' => $t->status, 'due_at' => $t->due_at?->toIso8601String(),
                'overdue' => $t->due_at && $t->due_at->isPast() && ! in_array($t->status, ['done', 'completed', 'cancelled'], true),
            ])->values();
        }

        return $out;
    }

    // ---- Assets ----

    public function assets(Employee $e): array
    {
        $items = DB::table('issuables')->get()->keyBy('id');
        $rows = IssueRecord::where('employee_id', $e->id)->orderByDesc('issued_on')->orderByDesc('id')->get()->map(function (IssueRecord $r) use ($items) {
            $open = $r->status === 'issued' || ($r->quantity - $r->returned_quantity) > 0 && $r->status !== 'returned';

            return [
                'id' => $r->id, 'name' => $items[$r->issuable_id]->name ?? null, 'kind' => $items[$r->issuable_id]->kind ?? null,
                'quantity' => $r->quantity, 'returned_quantity' => $r->returned_quantity, 'serial' => $r->serial, 'size' => $r->size,
                'issued_on' => $r->issued_on?->toDateString(), 'due_back_on' => $r->due_back_on?->toDateString(), 'returned_on' => $r->returned_on?->toDateString(),
                'status' => $r->status, 'return_condition' => $r->return_condition,
                'overdue' => $r->status === 'issued' && $r->due_back_on && $r->due_back_on->lt(today()),
            ];
        })->values();

        return ['current' => $rows->where('status', 'issued')->values(), 'history' => $rows->where('status', '!=', 'issued')->values(), 'overdue_count' => $rows->where('overdue', true)->count()];
    }

    // ---- Timeline ----

    public function timeline(Employee $e, User $u): array
    {
        $ev = [];
        $add = function (?string $date, string $kind, string $section, array $params = []) use (&$ev) {
            if ($date) {
                $ev[] = ['date' => substr($date, 0, 10), 'kind' => $kind, 'section' => $section, 'params' => $params];
            }
        };
        $add($e->hire_date->toDateString(), 'hired', 'employment');
        if ($e->termination_date) {
            $add($e->termination_date->toDateString(), 'terminated', 'employment', ['reason' => (string) $e->termination_reason]);
        }
        foreach (EContract::where('employee_id', $e->id)->get() as $c) {
            $add($c->employee_signed_at?->toDateString(), 'contract_signed', 'employment', ['number' => $c->number, 'kind' => $c->kind]);
        }
        foreach (Transfer::where('employee_id', $e->id)->where('status', 'completed')->get() as $t) {
            $h = $this->names('hotels', [$t->from_hotel_id, $t->to_hotel_id]);
            $add($t->effective_date->toDateString(), 'transfer', 'employment', ['from' => $h[$t->from_hotel_id] ?? '', 'to' => $h[$t->to_hotel_id] ?? '']);
        }
        foreach (LeaveRequest::where('employee_id', $e->id)->where('status', 'approved')->get() as $l) {
            $add(Carbon::parse($l->start_date)->toDateString(), 'leave', 'time', ['days' => (float) $l->days]);
        }
        if ($this->canPay($u)) {
            foreach (SalaryHistory::where('employee_id', $e->id)->whereIn('source', ['raise', 'promotion'])->get() as $h) {
                $add($h->effective_date->toDateString(), $h->source, 'pay', ['to' => (int) $h->base_salary]);
            }
            foreach (Payslip::with('run:id,period,status,paid_at')->where('employee_id', $e->id)->get() as $p) {
                if ($p->run && $p->run->status === 'paid') {
                    $add(($p->run->paid_at ?? $p->created_at)?->toDateString(), 'payslip_paid', 'pay', ['period' => $p->run->period, 'net' => (int) $p->net]);
                }
            }
        }
        if ($this->canDiscipline($u)) {
            foreach (DisciplinaryAction::where('employee_id', $e->id)->get() as $d) {
                $add(Carbon::parse($d->incident_date)->toDateString(), 'warning', 'conduct', ['kind' => (string) $d->kind]);
            }
        }
        if ($this->canTalent($u)) {
            $courses = DB::table('tal_courses')->get()->keyBy('id');
            $sessions = DB::table('tal_training_sessions')->get()->keyBy('id');
            foreach (TrainingAttendance::where('employee_id', $e->id)->where('result', 'passed')->get() as $a) {
                $s = $sessions[$a->session_id] ?? null;
                $add($s ? Carbon::parse($s->ends_on ?? $s->starts_on)->toDateString() : null, 'training_passed', 'growth', ['course' => (string) ($courses[$s->course_id ?? 0]->title ?? '')]);
            }
            $types = DB::table('tal_cert_types')->get()->keyBy('id');
            foreach (Certification::where('employee_id', $e->id)->whereNotNull('expires_on')->where('expires_on', '<=', today()->addDays(60))->get() as $c) {
                $add($c->expires_on->toDateString(), 'certificate_expiring', 'growth', ['name' => (string) ($types[$c->cert_type_id]->name ?? '')]);
            }
        }
        $items = DB::table('issuables')->get()->keyBy('id');
        foreach (IssueRecord::where('employee_id', $e->id)->get() as $r) {
            $add($r->issued_on?->toDateString(), 'asset_issued', 'assets', ['name' => (string) ($items[$r->issuable_id]->name ?? '')]);
        }

        return ['events' => collect($ev)->sortByDesc('date')->values()->take(200)];
    }

    // ---- Lookups ----

    private function hotelName(?int $id): ?string
    {
        return $id ? DB::table('hotels')->where('id', $id)->value('name') : null;
    }

    private function positionTitle(?int $id): ?string
    {
        return $id ? DB::table('positions')->where('id', $id)->value('title') : null;
    }

    /** @param Collection|array $ids */
    private function names(string $table, $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : DB::table($table)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function userName(int $id, ?Collection &$cache): ?string
    {
        $cache ??= collect();
        if (! $cache->has($id)) {
            $cache[$id] = DB::table('users')->where('id', $id)->value('name');
        }

        return $cache[$id];
    }
}

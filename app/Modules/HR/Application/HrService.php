<?php

namespace App\Modules\HR\Application;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\OnboardingTask;
use App\Modules\HR\Domain\OnboardingTemplate;
use App\Modules\HR\Domain\Position;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\LeaveType;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class HrService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx, private PublicHolidays $holidays) {}

    /** Reject a manager link that would make the org chart cyclic (or point outside the hotel). */
    public function assertNoCycle(?Employee $e, ?int $managerId): void
    {
        if ($managerId === null) {
            return;
        }
        $seen = [];
        $cur = Employee::find($managerId) ?? throw new DomainException('The manager is not an employee of this hotel.', 'invalid-manager');
        while ($cur) {
            if ($e && $cur->id === $e->id) {
                throw new DomainException('This reporting line would create a cycle in the org chart.', 'org-cycle');
            }
            if (isset($seen[$cur->id])) {
                break;
            }
            $seen[$cur->id] = true;
            $cur = $cur->reports_to ? Employee::find($cur->reports_to) : null;
        }
    }

    /** Org chart: departments, each with a manager tree of current employees. */
    public function orgChart(): array
    {
        $employees = Employee::with('department:id,name')->where('status', '!=', 'terminated')->orderBy('last_name')
            ->get(['id', 'number', 'first_name', 'last_name', 'photo_path', 'department_id', 'position_id', 'reports_to', 'status']);
        $positions = Position::pluck('title', 'id');
        $ids = $employees->pluck('id')->flip();
        $byManager = $employees->groupBy(fn ($e) => $e->reports_to && isset($ids[$e->reports_to]) ? $e->reports_to : 0);
        $node = function ($e) use (&$node, $byManager, $positions) {
            return ['id' => $e->id, 'number' => $e->number, 'name' => $e->full_name, 'photo_url' => $e->photo_url, 'position' => $positions[$e->position_id] ?? null,
                'department_id' => $e->department_id, 'reports' => ($byManager[$e->id] ?? collect())->map($node)->values()->all()];
        };

        return ($byManager[0] ?? collect())->groupBy(fn ($e) => $e->department_id ?? 0)->map(fn ($group, $deptId) => [
            'department_id' => $deptId ?: null,
            'department' => $group->first()->department?->name ?? 'Unassigned',
            'employees' => $group->map($node)->values()->all(),
        ])->values()->all();
    }

    /** Create the onboarding checklist from the position's template. */
    public function startOnboarding(Employee $e): void
    {
        $tpl = $e->position_id ? OnboardingTemplate::where('position_id', $e->position_id)->first() : null;
        foreach ($tpl?->tasks ?? [] as $task) {
            OnboardingTask::create(['employee_id' => $e->id, 'title' => $task['title'], 'owner_role' => $task['owner_role'] ?? null,
                'due_on' => $e->hire_date->copy()->addDays((int) ($task['due_days'] ?? 0))->toDateString()]);
        }
    }

    public function completeOnboardingTask(OnboardingTask $task): OnboardingTask
    {
        $u = $this->ctx->actor();
        if (! $u?->can('hr.manage') && ! ($task->owner_role && $u?->hasRole($task->owner_role))) {
            throw new DomainException('Only HR or the task owner role can complete this task.', 'forbidden', 403);
        }
        if (! $task->completed_at) {
            $task->update(['completed_at' => now(), 'completed_by' => $u?->id]);
            $this->bus->record('hr.onboarding_task_completed', ['id' => $task->id, 'employee_id' => $task->employee_id], 'onboarding_task', $task->id);
        }

        return $task;
    }

    public function storeDocument(Employee $e, UploadedFile $file, string $type, ?string $title, ?string $expiresOn): EmployeeDocument
    {
        $path = $file->storeAs("hr/{$e->hotel_id}/{$e->id}", Str::uuid().'.'.($file->guessExtension() ?: 'bin'), 'local');
        $doc = EmployeeDocument::create(['employee_id' => $e->id, 'type' => $type, 'title' => $title, 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            'expires_on' => $expiresOn, 'uploaded_by' => $this->ctx->actor()?->id]);
        $this->bus->record('hr.document_uploaded', ['id' => $doc->id, 'employee_id' => $e->id, 'type' => $type], 'employee_document', $doc->id);

        return $doc;
    }

    private function hotelCountry(): string
    {
        return Hotel::find($this->ctx->requireHotelId())?->country ?? 'CM';
    }

    public function hire(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $contract = $data['contract'];
            unset($data['contract']);
            $e = Employee::create($data + [
                'number' => NumberSequence::next($this->ctx->requireHotelId(), 'employee', 'E'),
                'base_salary' => $contract['base_salary'],
            ]);
            $e->contracts()->create($contract);
            \App\Modules\HR\Domain\Lifecycle\SalaryHistory::create(['hotel_id' => $e->hotel_id, 'employee_id' => $e->id, 'base_salary' => $e->base_salary, 'position_id' => $e->position_id, 'effective_date' => $contract['start_date'], 'source' => 'hire', 'recorded_by' => $this->ctx->actor()?->id]);
            $this->startOnboarding($e);
            $this->bus->record('hr.employee_hired', ['id' => $e->id, 'number' => $e->number, 'name' => $e->full_name, 'department_id' => $e->department_id], 'employee', $e->id);

            return $e->load('contracts');
        });
    }

    /** Edit an employee; pay changes are mirrored on the active contract (payroll reads the employee record). */
    public function updateEmployee(Employee $e, array $data): Employee
    {
        return DB::transaction(function () use ($e, $data) {
            $e->update($data);
            if (array_key_exists('base_salary', $data)) {
                $e->contracts()->where('status', 'active')->update(['base_salary' => $data['base_salary']]);
            }

            return $e->load('contracts');
        });
    }

    public function terminate(Employee $e, string $date, string $reason): Employee
    {
        $e->update(['status' => 'terminated', 'termination_date' => $date, 'termination_reason' => $reason]);
        $e->contracts()->where('status', 'active')->update(['status' => 'ended', 'end_date' => $date]);
        if ($e->user_id) {
            DB::table('users')->where('id', $e->user_id)->update(['status' => 'suspended']);
            DB::table('oauth_access_tokens')->where('user_id', $e->user_id)->update(['revoked' => true]);
        }
        $this->bus->record('hr.employee_terminated', ['id' => $e->id, 'date' => $date, 'reason' => $reason], 'employee', $e->id);

        return $e;
    }

    public function clockIn(Employee $e, string $source = 'app'): Attendance
    {
        if (Attendance::where('employee_id', $e->id)->whereNull('clock_out')->exists()) {
            throw new DomainException('Already clocked in.');
        }

        return Attendance::create(['employee_id' => $e->id, 'date' => now()->toDateString(), 'clock_in' => now(), 'source' => $source]);
    }

    public function clockOut(Employee $e, int $standardMinutes = 480): Attendance
    {
        $a = Attendance::where('employee_id', $e->id)->whereNull('clock_out')->latest('clock_in')->first()
            ?? throw new DomainException('Not clocked in.');
        $worked = (int) $a->clock_in->diffInMinutes(now());
        $a->update(['clock_out' => now(), 'worked_minutes' => $worked, 'overtime_minutes' => max(0, $worked - $standardMinutes)]);

        return $a;
    }

    /** Annual leave balance: accrued since hire − approved days taken. */
    public function leaveBalance(Employee $e): float
    {
        $months = $e->hire_date->diffInMonths(now());
        $accrued = $months * $e->leave_days_per_month;
        $annual = LeaveType::where('accrues', true)->pluck('id');
        $taken = (float) LeaveRequest::where('employee_id', $e->id)->whereIn('leave_type_id', $annual)->where('status', 'approved')->sum('days');

        return round($accrued - $taken, 1);
    }

    public function requestLeave(Employee $e, int $typeId, string $start, string $end, ?string $reason): LeaveRequest
    {
        $type = LeaveType::findOrFail($typeId);
        $days = 0;
        $holidays = $this->holidays->between($this->hotelCountry(), Carbon::parse($start)->toDateString(), Carbon::parse($end)->toDateString());
        foreach (CarbonPeriod::create($start, $end) as $d) {
            $days += ($d->isSunday() || isset($holidays[$d->toDateString()])) ? 0 : 1; // working days Mon–Sat, minus public holidays
        }
        if ($days <= 0) {
            throw new DomainException('The leave covers no working days.');
        }
        if ($type->accrues && $days > $this->leaveBalance($e)) {
            throw new DomainException('Not enough leave balance ('.$this->leaveBalance($e).' days).', 'insufficient-leave');
        }
        $overlap = LeaveRequest::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->exists();
        if ($overlap) {
            throw new DomainException('This overlaps another leave request.');
        }

        return LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end, 'days' => $days, 'reason' => $reason]);
    }

    public function decideLeave(LeaveRequest $req, bool $approve): LeaveRequest
    {
        if ($req->status !== 'pending') {
            throw new DomainException('This request is already decided.');
        }
        $req->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $this->ctx->actor()?->id]);
        $this->bus->record('hr.leave_'.($approve ? 'approved' : 'rejected'), ['id' => $req->id, 'employee_id' => $req->employee_id, 'days' => $req->days], 'leave_request', $req->id);

        return $req;
    }

    public function seedLeaveTypes(): void
    {
        foreach ([
            ['ANNUAL', 'Congé annuel', true, true], ['SICK', 'Maladie', true, false], ['MAT', 'Maternité', true, false],
            ['PAT', 'Paternité', true, false], ['EVENT', 'Événement familial', true, false], ['UNPAID', 'Sans solde', false, false],
        ] as [$code, $name, $paid, $accrues]) {
            LeaveType::firstOrCreate(['code' => $code], ['name' => $name, 'paid' => $paid, 'accrues' => $accrues]);
        }
    }
}

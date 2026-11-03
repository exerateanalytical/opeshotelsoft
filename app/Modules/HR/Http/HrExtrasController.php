<?php

namespace App\Modules\HR\Http;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Application\OvertimeService;
use App\Modules\HR\Application\PayrollService;
use App\Modules\HR\Application\PublicHolidays;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\OnboardingTask;
use App\Modules\HR\Domain\OnboardingTemplate;
use App\Modules\HR\Domain\OvertimeEntry;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Domain\PublicHoliday;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** W11: documents, org chart, onboarding, overtime, public holidays. */
class HrExtrasController
{
    public function __construct(private HrService $hr, private OvertimeService $overtime, private PublicHolidays $holidays, private TenantContext $ctx) {}

    // --- Documents -------------------------------------------------------

    public function documents(Request $request, Employee $employee)
    {
        $this->can($request, 'view');

        return EmployeeDocument::where('employee_id', $employee->id)->orderBy('type')->orderByDesc('id')->get()->makeHidden('path');
    }

    public function uploadDocument(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'type' => ['required', Rule::in(['contract', 'id', 'diploma', 'other'])],
            'title' => ['nullable', 'string', 'max:150'],
            'expires_on' => ['nullable', 'date'],
        ]);

        return response()->json($this->hr->storeDocument($employee, $data['file'], $data['type'], $data['title'] ?? null, $data['expires_on'] ?? null)->makeHidden('path'), 201);
    }

    /** Authorised download: HR, or the employee themself. Tenant scope makes other hotels 404. */
    public function download(Request $request, EmployeeDocument $document)
    {
        $own = Employee::where('id', $document->employee_id)->where('user_id', $request->user()->id)->exists();
        abort_unless($own || $request->user()->can('hr.view') || $request->user()->can('hr.manage'), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    /** Documents expired or expiring within N days (default 30). */
    public function expiring(Request $request)
    {
        $this->can($request, 'view');
        $days = min(365, max(0, (int) $request->query('days', 30)));

        return EmployeeDocument::with('employee:id,number,first_name,last_name')->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays($days)->toDateString())->orderBy('expires_on')->get()->makeHidden('path')
            ->each(fn ($d) => $d->setAttribute('expired', $d->expires_on->lt(now()->startOfDay())));
    }

    // --- Org chart -------------------------------------------------------

    public function orgChart(Request $request)
    {
        $this->can($request, 'view');

        return $this->hr->orgChart();
    }

    // --- Onboarding ------------------------------------------------------

    public function templates(Request $request)
    {
        $this->can($request, 'view');

        return OnboardingTemplate::orderBy('position_id')->get();
    }

    public function saveTemplate(Request $request, Position $position)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'tasks' => ['present', 'array', 'max:50'], 'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.owner_role' => ['nullable', 'string', 'exists:roles,name'], 'tasks.*.due_days' => ['sometimes', 'integer', 'between:0,365'],
        ]);

        return OnboardingTemplate::updateOrCreate(['position_id' => $position->id], ['tasks' => $data['tasks']]);
    }

    public function onboarding(Request $request, Employee $employee)
    {
        $this->can($request, 'view');

        return OnboardingTask::where('employee_id', $employee->id)->orderBy('due_on')->orderBy('id')->get();
    }

    /** Open onboarding tasks the current user can complete (HR: all; others: their roles). */
    public function myOnboardingTasks(Request $request)
    {
        $u = $request->user();
        abort_unless($u->can('hr.view') || $u->can('hr.manage') || $u->roles()->exists(), 403);

        return OnboardingTask::with('employee:id,number,first_name,last_name')->whereNull('completed_at')
            ->when(! $u->can('hr.manage'), fn ($q) => $q->whereIn('owner_role', $u->getRoleNames()))
            ->orderBy('due_on')->get();
    }

    public function completeTask(Request $request, OnboardingTask $task)
    {
        return $this->hr->completeOnboardingTask($task);
    }

    // --- Overtime --------------------------------------------------------

    public function overtimeEntries(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return OvertimeEntry::with('employee:id,number,first_name,last_name')
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')->orderBy('employee_id')->paginate(100);
    }

    public function computeOvertime(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return ['entries' => $this->overtime->compute($data['from'], $data['to'])];
    }

    public function decideOvertime(Request $request, OvertimeEntry $entry)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['approve' => ['required', 'boolean']]);

        return $this->overtime->decide($entry, $data['approve']);
    }

    /** The overtime multipliers of the hotel's rule pack (read-only, data not code). */
    public function overtimeRules(Request $request, PayrollService $payroll)
    {
        $this->can($request, 'view');
        $pack = $payroll->rulePackFor($this->country());

        return ['pack' => $pack->version, 'certified' => $pack->certified, 'rules' => PayrollService::paramsOf($pack)['overtime']];
    }

    // --- Public holidays -------------------------------------------------

    public function publicHolidays(Request $request)
    {
        $this->can($request, 'view');
        $year = (int) $request->query('year', now()->year);
        $country = strtoupper((string) $request->query('country', $this->country()));
        $this->holidays->ensureYear($country, $year);

        return PublicHoliday::where('country', $country)->whereYear('date', $year)->orderBy('date')->get();
    }

    /** Admin enters a movable (religious) holiday for a given year. */
    public function addPublicHoliday(Request $request)
    {
        abort_unless($request->user()->hasRole(['group-owner', 'general-manager']) || $request->user()->can('hr.manage'), 403);
        $data = $request->validate(['country' => ['sometimes', 'string', 'size:2'], 'date' => ['required', 'date'], 'name' => ['required', 'string', 'max:100']]);
        $country = strtoupper($data['country'] ?? $this->country());

        return response()->json(PublicHoliday::updateOrCreate(['country' => $country, 'date' => $data['date']], ['name' => $data['name'], 'movable' => true]), 201);
    }

    public function removePublicHoliday(Request $request, PublicHoliday $holiday)
    {
        abort_unless($request->user()->can('hr.manage'), 403);
        abort_unless($holiday->movable, 422, 'Fixed-date holidays come from the country rule pack.');
        $holiday->delete();

        return response()->noContent();
    }

    private function country(): string
    {
        return Hotel::find($this->ctx->requireHotelId())?->country ?? 'CM';
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('hr.manage') || ($ability === 'view' && ($u->can('hr.view') || $u->can('payroll.manage'))), 403);
    }
}

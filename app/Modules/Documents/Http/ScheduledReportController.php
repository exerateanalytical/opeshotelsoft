<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Application\ReportRegistry;
use App\Modules\Documents\Application\ScheduledReportRunner;
use App\Modules\Documents\Domain\ScheduledReport;
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduledReportController
{
    public function index(Request $request)
    {
        $this->can($request, 'view');

        return ScheduledReport::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $s = new ScheduledReport($this->validated($request) + ['created_by' => $request->user()->id]);
        $s->computeNextRun();
        $s->save();

        return response()->json($s, 201);
    }

    public function update(Request $request, ScheduledReport $scheduledReport)
    {
        $this->can($request, 'manage');
        $scheduledReport->fill($this->validated($request, true));
        $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return $scheduledReport;
    }

    public function destroy(Request $request, ScheduledReport $scheduledReport)
    {
        $this->can($request, 'manage');
        $scheduledReport->delete();

        return response()->noContent();
    }

    /** Send it now (runs as the schedule's creator). */
    public function run(Request $request, ScheduledReport $scheduledReport, ScheduledReportRunner $runner)
    {
        $this->can($request, 'manage');
        $runner->run($scheduledReport);

        return $scheduledReport->fresh();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:150'],
            'report' => [$req, Rule::in(array_keys(ReportRegistry::all()))],
            'params' => ['nullable', 'array'],
            'format' => [$req, 'in:xlsx,csv,pdf'],
            'recipients' => [$req, 'array', 'min:1', 'max:20'],
            'recipients.*' => ['email'],
            'frequency' => [$req, 'string', 'max:64', function ($attr, $v, $fail) {
                if (! CronExpression::isValidExpression($v)) {
                    $fail('The frequency must be a valid cron expression, e.g. "0 7 * * *".');
                }
            }],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('params', $data)) {
            $data['params'] ??= [];
        }

        return $data;
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('reports.manage') || ($ability === 'view' && $u->can('reports.view')), 403);
    }
}

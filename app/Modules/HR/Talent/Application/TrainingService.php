<?php

namespace App\Modules\HR\Talent\Application;

use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Talent\Domain\CertType;
use App\Modules\HR\Talent\Domain\Course;
use App\Modules\HR\Talent\Domain\EmployeeSkill;
use App\Modules\HR\Talent\Domain\TrainingAttendance;
use App\Modules\HR\Talent\Domain\TrainingSession;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Facades\DB;

/** Courses, sessions, attendance and results, cost, and the link to skills and certificates. */
class TrainingService
{
    public function __construct(private SkillsService $skills) {}

    public function createSession(array $d): TrainingSession
    {
        $course = Course::findOrFail($d['course_id']);

        return TrainingSession::create($d + ['cost' => $course->default_cost]);
    }

    public function enroll(TrainingSession $s, Employee $e): TrainingAttendance
    {
        if ($s->status !== 'planned') {
            throw new DomainException('This session is no longer open for enrolment.', 'invalid-state');
        }
        if ($s->capacity && TrainingAttendance::where('session_id', $s->id)->count() >= $s->capacity) {
            throw new DomainException('This session is full.', 'session-full');
        }
        if (TrainingAttendance::where(['session_id' => $s->id, 'employee_id' => $e->id])->exists()) {
            throw new DomainException('Already enrolled.', 'already-enrolled');
        }

        return TrainingAttendance::create(['session_id' => $s->id, 'employee_id' => $e->id]);
    }

    /** Record attendance and result; a pass applies the course's skill level and certificate once. */
    public function record(TrainingSession $s, Employee $e, string $attendance, ?string $result, ?float $score): TrainingAttendance
    {
        $a = TrainingAttendance::where(['session_id' => $s->id, 'employee_id' => $e->id])->firstOrFail();
        if ($s->status === 'cancelled') {
            throw new DomainException('This session was cancelled.', 'invalid-state');
        }
        if ($attendance !== 'attended') {
            $result = null;
        }

        return DB::transaction(function () use ($a, $s, $e, $attendance, $result, $score) {
            $a->update(['attendance' => $attendance, 'result' => $result, 'score' => $score]);
            if ($attendance === 'attended' && $result === 'passed' && ! $a->applied) {
                $course = Course::findOrFail($s->course_id);
                if ($course->skill_id && $course->skill_level) {
                    $cur = EmployeeSkill::where(['employee_id' => $e->id, 'skill_id' => $course->skill_id])->value('level') ?? 0;
                    if ($course->skill_level > $cur) {
                        $this->skills->setEmployeeSkill($e, $course->skill_id, $course->skill_level, ($s->ends_on ?? $s->starts_on)->toDateString());
                    }
                }
                if ($course->cert_type_id) {
                    $this->skills->addCertification($e, $course->cert_type_id, ($s->ends_on ?? $s->starts_on)->toDateString(), null, ['reference' => 'TRN-'.$s->id, 'issuer' => $s->trainer ?: $course->provider]);
                }
                $a->update(['applied' => true]);
            }

            return $a->fresh();
        });
    }

    /** Close a session: the session cost is split across the people who attended. */
    public function complete(TrainingSession $s): TrainingSession
    {
        if ($s->status !== 'planned') {
            throw new DomainException("This session is {$s->status}.", 'invalid-state');
        }
        $attended = TrainingAttendance::where('session_id', $s->id)->where('attendance', 'attended')->get();
        $share = $attended->isEmpty() ? 0 : intdiv($s->cost, $attended->count());
        foreach ($attended as $a) {
            $a->update(['cost' => $share]);
        }
        $s->update(['status' => 'done']);

        return $s;
    }

    public function cancel(TrainingSession $s): TrainingSession
    {
        if ($s->status === 'done') {
            throw new DomainException('A completed session cannot be cancelled.', 'invalid-state');
        }
        $s->update(['status' => 'cancelled']);

        return $s;
    }

    /** Training records of an employee with the course, date, result and cost. */
    public function recordsFor(int $employeeId): array
    {
        $rows = TrainingAttendance::where('employee_id', $employeeId)->get();
        $sessions = TrainingSession::whereIn('id', $rows->pluck('session_id'))->get()->keyBy('id');
        $courses = Course::whereIn('id', $sessions->pluck('course_id'))->get()->keyBy('id');

        return $rows->map(fn ($a) => ['session_id' => $a->session_id, 'course' => $courses[$sessions[$a->session_id]->course_id]->title, 'date' => $sessions[$a->session_id]->starts_on->toDateString(),
            'attendance' => $a->attendance, 'result' => $a->result, 'score' => $a->score, 'cost' => $a->cost])->sortByDesc('date')->values()->all();
    }

    /** Spend by department and total, for sessions completed in a year (tenant scoped through the models). */
    public function costReport(int $year): array
    {
        $sessions = TrainingSession::where('status', 'done')->whereYear('starts_on', $year)->pluck('id');
        $rows = TrainingAttendance::whereIn('session_id', $sessions)->where('attendance', 'attended')->get();
        $emps = Employee::whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');
        $depts = \App\Modules\HR\Domain\Department::get()->keyBy('id');
        $by = $rows->groupBy(fn ($a) => $depts[$emps[$a->employee_id]->department_id ?? 0]->name ?? '-')
            ->map(fn ($g, $name) => ['department' => $name, 'attendances' => $g->count(), 'cost' => (int) $g->sum('cost')])->values();

        return ['year' => $year, 'by_department' => $by->all(), 'total' => (int) $by->sum('cost')];
    }
}

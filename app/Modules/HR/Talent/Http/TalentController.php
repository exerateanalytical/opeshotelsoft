<?php

namespace App\Modules\HR\Talent\Http;

use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\HR\SelfService\SelfService;
use App\Modules\HR\Talent\Application\PayrollChecks;
use App\Modules\HR\Talent\Application\PlanningService;
use App\Modules\HR\Talent\Application\PrivacyService;
use App\Modules\HR\Talent\Application\RecruitmentService as R;
use App\Modules\HR\Talent\Application\SkillsService;
use App\Modules\HR\Talent\Application\TrainingService;
use App\Modules\HR\Talent\Domain\Applicant;
use App\Modules\HR\Talent\Domain\CertType;
use App\Modules\HR\Talent\Domain\ChangeRequest;
use App\Modules\HR\Talent\Domain\Course;
use App\Modules\HR\Talent\Domain\EmployeeSkill;
use App\Modules\HR\Talent\Domain\Interview;
use App\Modules\HR\Talent\Domain\JobPost;
use App\Modules\HR\Talent\Domain\Offer;
use App\Modules\HR\Talent\Domain\PositionSkill;
use App\Modules\HR\Talent\Domain\Skill;
use App\Modules\HR\Talent\Domain\Successor;
use App\Modules\HR\Talent\Domain\TrainingSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff API for recruitment, skills, training, planning, requests, payroll checks and privacy.
 * Permissions: talent.view (read), talent.recruit, talent.interview, talent.manage, talent.requests, talent.payroll_review, talent.privacy.
 */
class TalentController
{
    public function __construct(private R $rec, private SkillsService $skills, private TrainingService $training, private PlanningService $planning,
        private PayrollChecks $checks, private PrivacyService $privacy, private SelfService $self) {}

    private function need(Request $r, string ...$perms): void
    {
        foreach ($perms as $p) {
            if ($r->user()->can($p)) {
                return;
            }
        }
        abort(403);
    }

    // ---- recruitment ---------------------------------------------------------------

    public function posts(Request $r)
    {
        $this->need($r, 'talent.view');

        return JobPost::withCount('applicants')->orderByDesc('id')->get();
    }

    public function savePost(Request $r, ?JobPost $post = null)
    {
        $this->need($r, 'talent.recruit');
        $d = $r->validate([
            'title' => ['required', 'string', 'max:150'], 'department_id' => ['nullable', 'integer', 'exists:departments,id'], 'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'description' => ['nullable', 'string'], 'requirements' => ['nullable', 'string'], 'location' => ['nullable', 'string', 'max:120'],
            'contract_kind' => ['sometimes', Rule::in(['CDI', 'CDD', 'stage', 'journalier'])], 'salary_min' => ['nullable', 'integer', 'min:0'], 'salary_max' => ['nullable', 'integer', 'min:0'],
            'openings' => ['sometimes', 'integer', 'min:1', 'max:100'], 'closes_on' => ['nullable', 'date'],
        ]);
        if ($post) {
            $post->update($d);

            return $post;
        }

        return response()->json(JobPost::create($d), 201);
    }

    public function postStatus(Request $r, JobPost $post)
    {
        $this->need($r, 'talent.recruit');

        return $this->rec->setPostStatus($post, $r->validate(['status' => ['required', 'string']])['status']);
    }

    public function applicants(Request $r)
    {
        $this->need($r, 'talent.view');

        return Applicant::when($r->query('stage'), fn ($q, $s) => $q->where('stage', $s))->when($r->query('job_post_id'), fn ($q, $s) => $q->where('job_post_id', $s))
            ->when($r->boolean('pool'), fn ($q) => $q->where('talent_pool', true))->orderByDesc('id')->limit(500)->get();
    }

    public function addApplicant(Request $r)
    {
        $this->need($r, 'talent.recruit');
        $d = $r->validate(['first_name' => ['required', 'string'], 'last_name' => ['required', 'string'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string'],
            'job_post_id' => ['nullable', 'integer', 'exists:tal_job_posts,id'], 'source' => ['nullable', 'string', 'max:30'], 'consent' => ['accepted']]);
        unset($d['consent']);
        $s = $this->rec->settings();

        return response()->json(Applicant::create($d + ['consent_at' => now(), 'retain_until' => now()->addMonths($s->applicant_retention_months)->toDateString()]), 201);
    }

    public function showApplicant(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.view');
        $interviews = Interview::where('applicant_id', $applicant->id)->get()->map(fn ($i) => $i->toArray() + ['scorecard' => $this->rec->scorecard($i)]);

        return ['applicant' => $applicant, 'notes' => $applicant->notes()->orderByDesc('id')->get(), 'interviews' => $interviews, 'offers' => Offer::where('applicant_id', $applicant->id)->get()];
    }

    public function stage(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.recruit');
        $d = $r->validate(['stage' => ['required', 'string'], 'note' => ['nullable', 'string']]);

        return $this->rec->moveStage($applicant, $d['stage'], $d['note'] ?? null);
    }

    public function note(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.recruit', 'talent.interview');

        return response()->json($this->rec->addNote($applicant, $r->validate(['body' => ['required', 'string', 'max:4000']])['body']), 201);
    }

    public function pool(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.recruit');

        return $this->rec->setTalentPool($applicant, $r->boolean('in', true));
    }

    public function cv(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.recruit', 'talent.interview');
        $path = $this->rec->cvPath($applicant);
        abort_unless($path, 404);

        return response()->download($path, $applicant->cv_name ?: 'cv.pdf');
    }

    public function anonymise(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.privacy');

        return $this->rec->anonymise($applicant);
    }

    public function purge(Request $r)
    {
        $this->need($r, 'talent.privacy');

        return ['anonymised' => $this->rec->purgeExpired()];
    }

    public function schedule(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.recruit');
        $d = $r->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'location' => ['nullable', 'string'], 'kind' => ['nullable', Rule::in(['onsite', 'phone', 'video'])],
            'panel' => ['required', 'array', 'min:1'], 'panel.*' => ['integer', 'exists:users,id'], 'criteria' => ['nullable', 'array'], 'criteria.*' => ['string', 'max:60']]);

        return response()->json($this->rec->schedule($applicant, $d), 201);
    }

    public function score(Request $r, Interview $interview)
    {
        $this->need($r, 'talent.interview', 'talent.recruit');
        $d = $r->validate(['scores' => ['required', 'array', 'min:1'], 'scores.*.criterion' => ['required', 'string'], 'scores.*.score' => ['required', 'integer', 'between:1,5'],
            'recommendation' => ['required', Rule::in(['hire', 'maybe', 'no'])], 'comment' => ['nullable', 'string', 'max:4000']]);

        return $this->rec->score($interview, $r->user()->id, $d['scores'], $d['recommendation'], $d['comment'] ?? null);
    }

    public function scorecard(Request $r, Interview $interview)
    {
        $this->need($r, 'talent.view', 'talent.interview');

        return $this->rec->scorecard($interview);
    }

    public function offers(Request $r)
    {
        $this->need($r, 'talent.view');

        return Offer::orderByDesc('id')->get();
    }

    public function createOffer(Request $r, Applicant $applicant)
    {
        $this->need($r, 'talent.recruit');
        $d = $r->validate(['base_salary' => ['required', 'integer', 'min:1'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after:start_date'],
            'contract_kind' => ['nullable', Rule::in(['CDI', 'CDD', 'stage', 'journalier'])], 'department_id' => ['nullable', 'integer'], 'position_id' => ['nullable', 'integer'], 'message' => ['nullable', 'string']]);

        return response()->json($this->rec->createOffer($applicant, $d), 201);
    }

    public function sendOffer(Request $r, Offer $offer)
    {
        $this->need($r, 'talent.recruit');

        return $this->rec->sendOffer($offer);
    }

    public function withdrawOffer(Request $r, Offer $offer)
    {
        $this->need($r, 'talent.recruit');

        return $this->rec->withdrawOffer($offer);
    }

    /** HR can record an acceptance given on paper or by phone. */
    public function acceptOffer(Request $r, Offer $offer)
    {
        $this->need($r, 'talent.recruit');

        return $this->rec->accept($offer);
    }

    // ---- skills and certificates ---------------------------------------------------

    public function skillList(Request $r)
    {
        $this->need($r, 'talent.view');

        return ['skills' => Skill::orderBy('name')->get(), 'cert_types' => CertType::orderBy('name')->get(), 'position_skills' => PositionSkill::get(), 'courses' => Course::orderBy('title')->get()];
    }

    public function saveSkill(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'category' => ['nullable', 'string']]);

        return response()->json(Skill::updateOrCreate(['code' => $d['code']], $d), 201);
    }

    public function saveCertType(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'validity_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'mandatory' => ['sometimes', 'boolean'], 'alert_days' => ['sometimes', 'integer', 'between:1,365']]);

        return response()->json(CertType::updateOrCreate(['code' => $d['code']], $d), 201);
    }

    public function positionSkill(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['position_id' => ['required', 'integer'], 'skill_id' => ['required', 'integer'], 'required_level' => ['required', 'integer', 'between:1,5']]);

        return $this->skills->setPositionSkill($d['position_id'], $d['skill_id'], $d['required_level']);
    }

    public function employeeSkill(Request $r, Employee $employee)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['skill_id' => ['required', 'integer'], 'level' => ['required', 'integer', 'between:1,5'], 'assessed_on' => ['nullable', 'date']]);

        return $this->skills->setEmployeeSkill($employee, $d['skill_id'], $d['level'], $d['assessed_on'] ?? null);
    }

    public function matrix(Request $r)
    {
        $this->need($r, 'talent.view');

        return $this->skills->matrix($r->integer('department_id') ?: null);
    }

    public function gaps(Request $r)
    {
        $this->need($r, 'talent.view');

        return ['departments' => $this->skills->gaps()];
    }

    public function certifications(Request $r)
    {
        $this->need($r, 'talent.view');

        return $this->skills->certifications($r->query('status'));
    }

    public function addCertification(Request $r, Employee $employee)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['cert_type_id' => ['required', 'integer'], 'issued_on' => ['required', 'date'], 'expires_on' => ['nullable', 'date'], 'reference' => ['nullable', 'string'], 'issuer' => ['nullable', 'string']]);

        return response()->json($this->skills->addCertification($employee, $d['cert_type_id'], $d['issued_on'], $d['expires_on'] ?? null, array_filter(['reference' => $d['reference'] ?? null, 'issuer' => $d['issuer'] ?? null])), 201);
    }

    public function scanCertifications(Request $r)
    {
        $this->need($r, 'talent.manage');

        return ['alerts' => $this->skills->scanExpiries()];
    }

    // ---- training ------------------------------------------------------------------

    public function courses(Request $r)
    {
        $this->need($r, 'talent.view');

        return Course::orderBy('title')->get();
    }

    public function saveCourse(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['title' => ['required', 'string', 'max:150'], 'skill_id' => ['nullable', 'integer', 'exists:tal_skills,id'], 'skill_level' => ['nullable', 'integer', 'between:1,5'],
            'cert_type_id' => ['nullable', 'integer', 'exists:tal_cert_types,id'], 'duration_hours' => ['sometimes', 'numeric', 'min:0'], 'provider' => ['nullable', 'string'], 'default_cost' => ['sometimes', 'integer', 'min:0']]);

        return response()->json(Course::create($d), 201);
    }

    public function sessions(Request $r)
    {
        $this->need($r, 'talent.view');

        return TrainingSession::orderByDesc('starts_on')->get()->map(fn ($s) => $s->toArray() + ['attendances' => \App\Modules\HR\Talent\Domain\TrainingAttendance::where('session_id', $s->id)->get()]);
    }

    public function createSession(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['course_id' => ['required', 'integer'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'location' => ['nullable', 'string'],
            'trainer' => ['nullable', 'string'], 'capacity' => ['nullable', 'integer', 'min:1'], 'cost' => ['sometimes', 'integer', 'min:0']]);

        return response()->json($this->training->createSession($d), 201);
    }

    public function enroll(Request $r, TrainingSession $session)
    {
        $this->need($r, 'talent.manage');
        $ids = $r->validate(['employee_ids' => ['required', 'array', 'min:1'], 'employee_ids.*' => ['integer']])['employee_ids'];

        return collect($ids)->map(fn ($id) => $this->training->enroll($session, Employee::findOrFail($id)))->all();
    }

    public function record(Request $r, TrainingSession $session)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['employee_id' => ['required', 'integer'], 'attendance' => ['required', Rule::in(['enrolled', 'attended', 'absent'])], 'result' => ['nullable', Rule::in(['passed', 'failed'])], 'score' => ['nullable', 'numeric', 'between:0,100']]);

        return $this->training->record($session, Employee::findOrFail($d['employee_id']), $d['attendance'], $d['result'] ?? null, $d['score'] ?? null);
    }

    public function completeSession(Request $r, TrainingSession $session)
    {
        $this->need($r, 'talent.manage');

        return $this->training->complete($session);
    }

    public function cancelSession(Request $r, TrainingSession $session)
    {
        $this->need($r, 'talent.manage');

        return $this->training->cancel($session);
    }

    public function trainingRecords(Request $r, Employee $employee)
    {
        $this->need($r, 'talent.view');

        return $this->training->recordsFor($employee->id);
    }

    public function trainingCost(Request $r)
    {
        $this->need($r, 'talent.view');

        return $this->training->costReport($r->integer('year') ?: (int) now()->format('Y'));
    }

    // ---- headcount and succession --------------------------------------------------

    public function headcount(Request $r)
    {
        $this->need($r, 'talent.view');
        $d = $r->validate(['from' => ['nullable', 'date_format:Y-m'], 'to' => ['nullable', 'date_format:Y-m']]);

        return $this->planning->headcount($d['from'] ?? now()->format('Y-m'), $d['to'] ?? now()->addMonths(5)->format('Y-m'));
    }

    public function saveBudget(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['department_id' => ['required', 'integer'], 'month' => ['required', 'date_format:Y-m'], 'budgeted' => ['required', 'integer', 'min:0', 'max:5000']]);

        return $this->planning->setBudget($d['department_id'], $d['month'], $d['budgeted']);
    }

    public function succession(Request $r)
    {
        $this->need($r, 'talent.view');

        return ['positions' => $this->planning->succession()];
    }

    public function keyPosition(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['position_id' => ['required', 'integer'], 'risk' => ['required', Rule::in(['low', 'medium', 'high'])], 'notes' => ['nullable', 'string']]);

        return $this->planning->markKey($d['position_id'], $d['risk'], $d['notes'] ?? null);
    }

    public function successor(Request $r)
    {
        $this->need($r, 'talent.manage');
        $d = $r->validate(['position_id' => ['required', 'integer'], 'employee_id' => ['required', 'integer'], 'readiness' => ['required', Rule::in(PlanningService::READINESS)], 'notes' => ['nullable', 'string']]);

        return response()->json($this->planning->addSuccessor($d['position_id'], $d['employee_id'], $d['readiness'], $d['notes'] ?? null), 201);
    }

    public function removeSuccessor(Request $r, Successor $successor)
    {
        $this->need($r, 'talent.manage');
        $successor->delete();

        return response()->noContent();
    }

    // ---- self-service requests -----------------------------------------------------

    public function requests(Request $r)
    {
        $this->need($r, 'talent.requests');

        return ChangeRequest::with([])->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->limit(300)->get()
            ->map(fn ($x) => $x->toArray() + ['employee' => Employee::find($x->employee_id)?->full_name]);
    }

    public function decideRequest(Request $r, ChangeRequest $req)
    {
        $this->need($r, 'talent.requests');
        $d = $r->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->self->decide($req, (bool) $d['approve'], $d['note'] ?? null);
    }

    // ---- payroll checks and settings -----------------------------------------------

    public function payrollChecks(Request $r, PayrollRun $run)
    {
        $this->need($r, 'talent.payroll_review', 'payroll.manage', 'payroll.view');
        $rev = $this->checks->review($run);

        return ['run_id' => $run->id, 'period' => $run->period, 'required' => $this->checks->settings()->payroll_review_required, 'checks' => $rev->checks, 'acknowledged' => $rev->acknowledged,
            'pending' => $this->checks->pending($run), 'reviewed_at' => $rev->reviewed_at, 'comment' => $rev->comment];
    }

    public function acknowledge(Request $r, PayrollRun $run)
    {
        $this->need($r, 'talent.payroll_review');
        $d = $r->validate(['codes' => ['required', 'array'], 'codes.*' => ['string'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return $this->checks->acknowledge($run, $d['codes'], $d['comment'] ?? null);
    }

    public function settings(Request $r)
    {
        $this->need($r, 'talent.view');

        return $this->checks->settings();
    }

    public function saveSettings(Request $r)
    {
        $this->need($r, 'talent.payroll_review', 'talent.privacy');
        $d = $r->validate(['payroll_review_required' => ['sometimes', 'boolean'], 'variance_pct' => ['sometimes', 'integer', 'between:1,500'], 'overtime_multiple' => ['sometimes', 'numeric', 'between:1,20'],
            'applicant_retention_months' => ['sometimes', 'integer', 'between:1,120'], 'pool_retention_months' => ['sometimes', 'integer', 'between:1,120'], 'leaver_retention_months' => ['sometimes', 'integer', 'between:1,240']]);
        $s = $this->checks->settings();
        $s->update($d);

        return $s->fresh();
    }

    // ---- privacy -------------------------------------------------------------------

    public function privacyEligible(Request $r)
    {
        $this->need($r, 'talent.privacy');

        return ['employees' => $this->privacy->eligible()];
    }

    public function export(Request $r, Employee $employee)
    {
        $this->need($r, 'talent.privacy');
        if ($r->query('format') === 'xlsx') {
            return response($this->privacy->xlsx($employee), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="employee-'.$employee->number.'.xlsx"']);
        }

        return $this->privacy->export($employee);
    }

    public function erase(Request $r, Employee $employee)
    {
        $this->need($r, 'talent.privacy');

        return $this->privacy->erase($employee);
    }
}

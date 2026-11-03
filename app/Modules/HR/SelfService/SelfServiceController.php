<?php

namespace App\Modules\HR\SelfService;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Talent\Application\TrainingService;
use App\Modules\HR\Talent\Domain\Certification;
use App\Modules\HR\Talent\Domain\CertType;
use App\Modules\HR\Talent\Domain\ChangeRequest;
use App\Modules\HR\Workforce\Application\LeavePolicyService;
use App\Modules\HR\Workforce\Domain\RosterShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** /me API: every endpoint acts on the employee linked to the signed-in user, never on an id from the request. */
class SelfServiceController
{
    public function __construct(private SelfService $me, private HrService $hr, private LeavePolicyService $leave, private TrainingService $training) {}

    public function profile(Request $r)
    {
        return $this->me->profile($this->me->employeeFor($r->user()));
    }

    public function leave(Request $r)
    {
        $e = $this->me->employeeFor($r->user());

        return ['balance' => $this->hr->leaveBalance($e), 'balances' => $this->leave->balances($e->id, null), 'types' => LeaveType::orderBy('name')->get(['id', 'code', 'name']),
            'requests' => LeaveRequest::where('employee_id', $e->id)->orderByDesc('id')->limit(50)->get()];
    }

    public function requestLeave(Request $r)
    {
        $e = $this->me->employeeFor($r->user());
        $d = $r->validate(['leave_type_id' => ['required', 'integer'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->hr->requestLeave($e, $d['leave_type_id'], $d['start_date'], $d['end_date'], $d['reason'] ?? null)->fresh(), 201);
    }

    public function schedule(Request $r)
    {
        $e = $this->me->employeeFor($r->user());

        return RosterShift::where('employee_id', $e->id)->whereHas('roster', fn ($q) => $q->where('status', 'published'))
            ->whereBetween('date', [now()->toDateString(), now()->addDays(30)->toDateString()])->orderBy('date')->orderBy('starts_at')->get();
    }

    public function documents(Request $r)
    {
        $e = $this->me->employeeFor($r->user());
        $types = CertType::get()->keyBy('id');

        return [
            'documents' => EmployeeDocument::where('employee_id', $e->id)->get(['id', 'type', 'title', 'original_name', 'expires_on']),
            'certificates' => Certification::where('employee_id', $e->id)->orderByDesc('issued_on')->get()->map(fn ($c) => ['id' => $c->id, 'type' => $types[$c->cert_type_id]->name ?? '', 'issued_on' => $c->issued_on->toDateString(), 'expires_on' => $c->expires_on?->toDateString()]),
            'training' => $this->training->recordsFor($e->id),
        ];
    }

    public function downloadDocument(Request $r, int $document)
    {
        $e = $this->me->employeeFor($r->user());
        $d = EmployeeDocument::where('employee_id', $e->id)->findOrFail($document);
        abort_unless($d->path && Storage::disk('local')->exists($d->path), 404);

        return Storage::disk('local')->download($d->path, $d->original_name);
    }

    public function contracts(Request $r)
    {
        return $this->me->contractsToSign($this->me->employeeFor($r->user()));
    }

    public function contractLink(Request $r, int $contract)
    {
        return $this->me->signingLink($this->me->employeeFor($r->user()), $contract);
    }

    public function requests(Request $r)
    {
        $e = $this->me->employeeFor($r->user());

        return ChangeRequest::where('employee_id', $e->id)->orderByDesc('id')->limit(50)->get();
    }

    public function storeRequest(Request $r)
    {
        $e = $this->me->employeeFor($r->user());
        $d = $r->validate(['kind' => ['required', Rule::in(SelfService::KINDS)], 'address' => ['nullable', 'string', 'max:200'], 'phone' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:100'], 'bank_account' => ['nullable', 'string', 'max:60'], 'momo_number' => ['nullable', 'string', 'max:30'],
            'purpose' => ['nullable', 'string', 'max:200'], 'lang' => ['nullable', Rule::in(['fr', 'en'])]]);

        return response()->json($this->me->request($e, $d['kind'], collect($d)->except('kind')->filter(fn ($v) => $v !== null)->all()), 201);
    }

    public function certificate(Request $r, int $req)
    {
        $e = $this->me->employeeFor($r->user());
        $x = ChangeRequest::where('employee_id', $e->id)->findOrFail($req);

        return response($this->me->certificate($x), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="certificate-'.$x->id.'.pdf"']);
    }
}

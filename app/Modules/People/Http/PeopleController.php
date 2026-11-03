<?php

namespace App\Modules\People\Http;

use App\Modules\People\Application\PeopleService;
use App\Modules\People\Domain\DisciplinaryAction;
use App\Modules\People\Domain\DisciplinaryDocument;
use App\Modules\People\Domain\ExitCase;
use App\Modules\People\Domain\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Guest feedback on staff, discipline, exits. */
class PeopleController
{
    use Authorizes;

    public function __construct(private PeopleService $people) {}

    // ---- Feedback -------------------------------------------------------

    public function feedback(Request $request)
    {
        $this->need($request, 'people.view', 'people.manage');
        $rows = Feedback::when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('sentiment'), fn ($q, $v) => $q->where('sentiment', $v))
            ->orderByDesc('received_on')->orderByDesc('id')->paginate(min(max((int) $request->query('per_page', 50), 1), 200));

        return $this->withNames($rows, 'employee_id');
    }

    public function storeFeedback(Request $request)
    {
        $this->need($request, 'people.manage');
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'guest_id' => ['nullable', 'integer'],
            'rating' => ['nullable', 'integer', 'between:1,5'], 'sentiment' => ['nullable', Rule::in(['positive', 'neutral', 'negative'])],
            'comment' => ['nullable', 'string', 'max:5000'], 'received_on' => ['nullable', 'date'],
        ]);

        return response()->json($this->people->recordFeedback(array_filter($data, fn ($v) => $v !== null)), 201);
    }

    public function feedbackSummary(Request $request, int $employee)
    {
        $this->need($request, 'people.view', 'people.manage');

        return $this->people->feedbackSummary($employee);
    }

    // ---- Discipline -----------------------------------------------------

    public function sanctions(Request $request)
    {
        $me = $this->me($request);
        $all = $this->has($request, 'people.discipline');
        $rows = DisciplinaryAction::withCount('documents')
            ->when(! $all, fn ($q) => $q->where('employee_id', $me?->id ?? 0))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('incident_date')->orderByDesc('id')->get();

        return $this->withNames($rows, 'employee_id');
    }

    public function storeSanction(Request $request)
    {
        $this->need($request, 'people.discipline');
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'kind' => ['required', Rule::in(['verbal_warning', 'written_warning', 'suspension'])],
            'incident_date' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:200'],
            'facts' => ['nullable', 'string'], 'suspension_start' => ['nullable', 'date'], 'suspension_end' => ['nullable', 'date', 'after_or_equal:suspension_start'],
        ]);

        return response()->json($this->people->issueSanction($data), 201);
    }

    public function showSanction(Request $request, DisciplinaryAction $sanction)
    {
        $this->canSeeSanction($request, $sanction);

        return $this->withNames(collect([$sanction->load('documents')]), 'employee_id')->first();
    }

    /** The employee acknowledges receipt (or refuses to sign); HR may record it on their behalf. */
    public function acknowledge(Request $request, DisciplinaryAction $sanction)
    {
        if ($this->me($request)?->id !== $sanction->employee_id) {
            $this->need($request, 'people.discipline');
        }
        $data = $request->validate(['refused' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->people->acknowledge($sanction, (bool) ($data['refused'] ?? false), $data['note'] ?? null);
    }

    public function withdraw(Request $request, DisciplinaryAction $sanction)
    {
        $this->need($request, 'people.discipline');

        return $this->people->withdraw($sanction);
    }

    public function attach(Request $request, DisciplinaryAction $sanction)
    {
        $this->need($request, 'people.discipline');
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'], 'title' => ['nullable', 'string', 'max:150']]);

        return response()->json($this->people->attach($sanction, $request->file('file'), $data['title'] ?? null), 201);
    }

    public function downloadDocument(Request $request, DisciplinaryAction $sanction, DisciplinaryDocument $document)
    {
        $this->canSeeSanction($request, $sanction);
        abort_unless($document->action_id === $sanction->id, 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    private function canSeeSanction(Request $request, DisciplinaryAction $s): void
    {
        if ($this->me($request)?->id !== $s->employee_id) {
            $this->need($request, 'people.discipline');
        }
    }

    // ---- Exit -----------------------------------------------------------

    public function exits(Request $request)
    {
        $this->need($request, 'people.exit');

        return $this->withNames(ExitCase::orderByDesc('last_day')->get(), 'employee_id');
    }

    public function storeExit(Request $request)
    {
        $this->need($request, 'people.exit');

        return response()->json($this->people->openExit($this->exitData($request, true)), 201);
    }

    public function showExit(Request $request, ExitCase $exit)
    {
        $this->need($request, 'people.exit');

        return $this->withNames(collect([$exit]), 'employee_id')->first();
    }

    public function updateExit(Request $request, ExitCase $exit)
    {
        $this->need($request, 'people.exit');
        if ($exit->status !== 'draft') {
            abort(422, 'An approved settlement cannot be changed.');
        }
        $exit->fill($this->exitData($request, false))->save();

        return $this->people->recalculate($exit);
    }

    public function approveExit(Request $request, ExitCase $exit)
    {
        $this->need($request, 'people.exit');

        return $this->people->approveExit($exit);
    }

    public function certificate(Request $request, ExitCase $exit)
    {
        $this->need($request, 'people.exit');
        $lang = $request->query('lang', $request->user()->locale ?? 'fr');

        $doc = $this->people->workCertificatePdf($exit, (string) $lang);

        return response($doc->pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$doc->filename.'"', 'Cache-Control' => 'private, no-store']);
    }

    private function exitData(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return $request->validate(array_filter([
            'employee_id' => $create ? ['required', 'integer'] : null,
            'kind' => [$req, Rule::in(['resignation', 'termination', 'end_of_contract'])],
            'reason' => ['nullable', 'string', 'max:255'], 'gross_misconduct' => ['sometimes', 'boolean'],
            'notice_date' => [$req, 'date'], 'last_day' => [$req, 'date', 'after_or_equal:notice_date'],
            'notice_worked' => ['sometimes', 'boolean'], 'is_executive' => ['sometimes', 'boolean'],
        ]));
    }
}

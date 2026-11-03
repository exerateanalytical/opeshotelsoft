<?php

namespace App\Modules\People\Application;

use App\Modules\HR\Application\HrService;
use App\Modules\People\Domain\DisciplinaryAction;
use App\Modules\People\Domain\DisciplinaryDocument;
use App\Modules\People\Domain\ExitCase;
use App\Modules\People\Domain\Feedback;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Guest feedback on staff, discipline, and exits (settlement + work certificate). */
class PeopleService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx, private EmployeeDirectory $people,
        private SettlementCalculator $calculator, private HrService $hr) {}

    // ---- Guest feedback -------------------------------------------------

    public function recordFeedback(array $data): Feedback
    {
        $this->people->get((int) $data['employee_id']);
        $data['sentiment'] ??= $this->sentiment($data['rating'] ?? null);
        $data['received_on'] ??= now()->toDateString();

        return Feedback::create($data + ['source' => 'manual', 'recorded_by' => $this->ctx->actor()?->id]);
    }

    /**
     * A guest review that names staff (event contract: id, rating 1–5, comment,
     * guest_id, staff_employee_ids[]). Idempotent per review and employee.
     *
     * @return Feedback[]
     */
    public function feedbackFromReview(array $review): array
    {
        $out = [];
        foreach (array_unique($review['staff_employee_ids'] ?? []) as $employeeId) {
            $out[] = Feedback::firstOrCreate(
                ['source' => 'review', 'external_ref' => (string) $review['id'], 'employee_id' => (int) $employeeId],
                ['guest_id' => $review['guest_id'] ?? null, 'rating' => $review['rating'] ?? null, 'comment' => $review['comment'] ?? null,
                    'sentiment' => $this->sentiment($review['rating'] ?? null), 'received_on' => $review['date'] ?? now()->toDateString()]);
        }

        return $out;
    }

    public function feedbackSummary(int $employeeId): array
    {
        $rows = Feedback::where('employee_id', $employeeId)->get();

        return ['count' => $rows->count(), 'avg_rating' => $rows->whereNotNull('rating')->avg('rating') ? round($rows->whereNotNull('rating')->avg('rating'), 2) : null,
            'positive' => $rows->where('sentiment', 'positive')->count(), 'neutral' => $rows->where('sentiment', 'neutral')->count(),
            'negative' => $rows->where('sentiment', 'negative')->count()];
    }

    private function sentiment(?int $rating): string
    {
        return $rating === null ? 'neutral' : ($rating >= 4 ? 'positive' : ($rating <= 2 ? 'negative' : 'neutral'));
    }

    // ---- Discipline -----------------------------------------------------

    public function issueSanction(array $data): DisciplinaryAction
    {
        $this->people->get((int) $data['employee_id']);
        if ($data['kind'] === 'suspension' && (empty($data['suspension_start']) || empty($data['suspension_end']))) {
            throw new DomainException('A suspension needs a start and end date.', 'people-suspension-dates');
        }
        if ($data['kind'] === 'suspension' && now()->parse($data['suspension_start'])->diffInDays(now()->parse($data['suspension_end'])) + 1 > 8) {
            // Cameroon Labour Code art. 30: disciplinary lay-off may not exceed 8 days.
            throw new DomainException('A disciplinary suspension cannot exceed 8 days.', 'people-suspension-too-long');
        }
        $a = DisciplinaryAction::create($data + ['status' => 'issued', 'issued_by' => $this->ctx->actor()?->id]);
        $this->bus->record('people.sanction_issued', ['id' => $a->id, 'employee_id' => $a->employee_id, 'kind' => $a->kind,
            'suspension_start' => $a->suspension_start?->toDateString(), 'suspension_end' => $a->suspension_end?->toDateString()], 'disciplinary_action', $a->id);

        return $a;
    }

    public function acknowledge(DisciplinaryAction $a, bool $refused, ?string $note): DisciplinaryAction
    {
        if ($a->status !== 'issued') {
            throw new DomainException('This sanction was already '.$a->status.'.', 'people-sanction-status');
        }
        $a->update(['status' => $refused ? 'refused' : 'acknowledged', 'acknowledged_at' => now(), 'acknowledgement_note' => $note]);

        return $a;
    }

    public function withdraw(DisciplinaryAction $a): DisciplinaryAction
    {
        $a->update(['status' => 'withdrawn']);
        \App\Modules\Documents\Operations\Versions::voidAll('warning_letter', $a->id, 'Sanction withdrawn');

        return $a;
    }

    public function attach(DisciplinaryAction $a, UploadedFile $file, ?string $title): DisciplinaryDocument
    {
        $path = $file->storeAs("people/discipline/{$a->hotel_id}/{$a->id}", Str::uuid().'.'.($file->guessExtension() ?: 'bin'), 'local');

        return DisciplinaryDocument::create(['action_id' => $a->id, 'title' => $title, 'path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $this->ctx->actor()?->id]);
    }

    // ---- Exit -----------------------------------------------------------

    public function openExit(array $data): ExitCase
    {
        $e = $this->people->get((int) $data['employee_id']);
        if (ExitCase::where('employee_id', $e->id)->exists()) {
            throw new DomainException('An exit is already recorded for this employee.', 'people-exit-exists');
        }
        $exit = ExitCase::create($data + ['status' => 'draft', 'gross_misconduct' => false, 'notice_worked' => true, 'is_executive' => false]);

        return $this->recalculate($exit);
    }

    public function recalculate(ExitCase $exit): ExitCase
    {
        if ($exit->status !== 'draft') {
            throw new DomainException('An approved settlement cannot be changed.', 'people-exit-approved');
        }
        $e = $this->people->get($exit->employee_id);
        $s = $this->calculator->calculate($exit->kind, $exit->gross_misconduct, $exit->notice_worked, $exit->is_executive,
            $e->hire_date, $exit->last_day, (int) $e->base_salary, $this->people->leaveBalance($e));
        $exit->update(['settlement' => $s, 'settlement_total' => max(0, $s['total'])]);

        return $exit;
    }

    public function approveExit(ExitCase $exit): ExitCase
    {
        if ($exit->status !== 'draft') {
            throw new DomainException('Already approved.', 'people-exit-approved');
        }

        return DB::transaction(function () use ($exit) {
            $e = $this->people->get($exit->employee_id);
            $exit->update(['status' => 'approved', 'approved_by' => $this->ctx->actor()?->id, 'approved_at' => now(),
                'certificate_number' => 'CT-'.now()->format('Y').'-'.str_pad((string) $exit->id, 5, '0', STR_PAD_LEFT)]);
            if ($e->status !== 'terminated') {
                $this->hr->terminate($e, $exit->last_day->toDateString(), $exit->kind.($exit->reason ? ": $exit->reason" : ''));
            }
            $this->bus->record('people.exit_approved', ['id' => $exit->id, 'employee_id' => $exit->employee_id, 'kind' => $exit->kind,
                'last_day' => $exit->last_day->toDateString(), 'settlement' => $exit->settlement], 'people_exit', $exit->id);

            return $exit;
        });
    }

    /** Certificat de travail (Labour Code art. 44): dates and positions only, no reason for leaving. */
    public function workCertificatePdf(ExitCase $exit, string $lang)
    {
        if ($exit->status !== 'approved') {
            throw new DomainException('Approve the exit before issuing the work certificate.', 'people-exit-not-approved');
        }
        $e = $this->people->get($exit->employee_id);
        $hotel = Hotel::find($exit->hotel_id);

        $lang = $lang === 'en' ? 'en' : 'fr';

        return app(\App\Modules\Documents\Application\DocumentRenderer::class)->render('work_certificate', ['exit' => $exit, 'employee' => $e,
            'position' => $this->people->positionTitle($e)], ['view' => 'people::work-certificate', 'hotel' => $hotel, 'lang' => $lang, 'number' => $exit->certificate_number,
            'source_type' => 'exit_case', 'source_id' => $exit->id, 'title' => $lang === 'en' ? 'Work certificate' : 'Certificat de travail',
            'filename' => "certificat-travail-{$exit->certificate_number}"]);
    }
}

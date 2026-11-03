<?php

namespace App\Modules\HR\Talent\Application;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Application\Lifecycle\EContractService;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Talent\Domain\Applicant;
use App\Modules\HR\Talent\Domain\ApplicantNote;
use App\Modules\HR\Talent\Domain\Interview;
use App\Modules\HR\Talent\Domain\InterviewScore;
use App\Modules\HR\Talent\Domain\JobPost;
use App\Modules\HR\Talent\Domain\Offer;
use App\Modules\HR\Talent\Domain\TalentSettings;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Recruitment: job posts, public applications, pipeline, interviews and scoring, offers
 * that become an employee and a Q2 e-contract, talent pool and retention.
 */
class RecruitmentService
{
    public const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'];

    public const OFFER_DAYS = 10;

    public const DEFAULT_CRITERIA = ['Experience', 'Attitude', 'Communication', 'Technical skills'];

    public function __construct(private TenantContext $ctx, private EventBus $bus, private Notifier $notifier, private HrService $hr, private EContractService $contracts) {}

    public function settings(): TalentSettings
    {
        return TalentSettings::firstOrCreate(['hotel_id' => $this->ctx->requireHotelId()]);
    }

    // ---- job posts -----------------------------------------------------------------

    public function setPostStatus(JobPost $post, string $status): JobPost
    {
        if (! in_array($status, ['draft', 'open', 'closed'], true)) {
            throw new DomainException('Unknown job post status.');
        }
        $post->update(['status' => $status, 'published_at' => $status === 'open' ? ($post->published_at ?? now()) : $post->published_at]);

        return $post;
    }

    /** Open job posts of a group, for the public careers page. */
    public function publicPosts(string $groupSlug): array
    {
        $group = Group::where('slug', $groupSlug)->where('status', 'active')->firstOrFail();

        return $this->ctx->withoutScope(function () use ($group) {
            $hotels = Hotel::withoutGlobalScopes()->where('group_id', $group->id)->get()->keyBy('id');

            return [
                'group' => ['name' => $group->name, 'slug' => $group->slug],
                'posts' => JobPost::withoutGlobalScopes()->where('group_id', $group->id)->where('status', 'open')
                    ->where(fn ($q) => $q->whereNull('closes_on')->orWhere('closes_on', '>=', now()->toDateString()))
                    ->orderByDesc('published_at')->get()->map(fn (JobPost $p) => [
                        'id' => $p->id, 'title' => $p->title, 'description' => $p->description, 'requirements' => $p->requirements,
                        'location' => $p->location ?: $hotels[$p->hotel_id]?->city, 'contract_kind' => $p->contract_kind,
                        'hotel' => $hotels[$p->hotel_id]?->name, 'closes_on' => $p->closes_on?->toDateString(),
                    ])->values()->all(),
            ];
        });
    }

    /** Public application. Creates a pipeline entry in the post's hotel; consent is mandatory. */
    public function apply(string $groupSlug, int $postId, array $data, ?UploadedFile $cv): Applicant
    {
        $group = Group::where('slug', $groupSlug)->where('status', 'active')->firstOrFail();
        $post = $this->ctx->withoutScope(fn () => JobPost::withoutGlobalScopes()->where('group_id', $group->id)->where('status', 'open')->find($postId));
        if (! $post || ($post->closes_on && $post->closes_on->isPast())) {
            throw new DomainException('This job post is not open for applications.', 'post-closed', 404);
        }
        $dup = $this->ctx->withoutScope(fn () => Applicant::withoutGlobalScopes()->where('job_post_id', $post->id)->where('email', $data['email'])->whereNotIn('stage', ['rejected', 'withdrawn'])->exists());
        if ($dup) {
            throw new DomainException('You have already applied to this job.', 'duplicate-application');
        }
        $path = $name = null;
        if ($cv) {
            $path = $cv->storeAs("talent/cv/{$post->hotel_id}", Str::uuid().'.'.($cv->guessExtension() ?: 'pdf'), 'local');
            $name = $cv->getClientOriginalName();
        }
        $prev = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($post->group_id, $post->hotel_id, [$post->hotel_id], null);
        try {
            $months = $this->settings()->applicant_retention_months;
            $a = Applicant::create([
                'group_id' => $post->group_id, 'hotel_id' => $post->hotel_id, 'job_post_id' => $post->id,
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'cover_note' => $data['cover_note'] ?? null, 'cv_path' => $path, 'cv_name' => $name, 'source' => 'careers',
                'consent_at' => now(), 'retain_until' => now()->addMonths($months)->toDateString(),
            ]);
            $this->bus->record('talent.application_received', ['id' => $a->id, 'job_post_id' => $post->id], 'applicant', $a->id);
        } finally {
            $this->ctx->set(...$prev);
        }

        return $a;
    }

    // ---- pipeline ------------------------------------------------------------------

    public function addNote(Applicant $a, string $body, string $kind = 'note', ?string $stage = null): ApplicantNote
    {
        return ApplicantNote::create(['applicant_id' => $a->id, 'user_id' => $this->ctx->actor()?->id, 'kind' => $kind, 'stage' => $stage, 'body' => $body]);
    }

    public function moveStage(Applicant $a, string $stage, ?string $note = null): Applicant
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new DomainException('Unknown stage.');
        }
        if ($a->anonymised_at) {
            throw new DomainException('This applicant has been anonymised.', 'anonymised');
        }
        if ($a->stage === 'hired') {
            throw new DomainException('A hired applicant cannot be moved.', 'invalid-state');
        }
        if ($stage === 'hired') {
            throw new DomainException('An applicant becomes hired when the offer is accepted.', 'invalid-state');
        }
        $s = $this->settings();
        $a->update(['stage' => $stage]);
        if ($stage === 'rejected' || $stage === 'withdrawn') {
            $a->update(['retain_until' => now()->addMonths($a->talent_pool ? $s->pool_retention_months : $s->applicant_retention_months)->toDateString()]);
        }
        $this->addNote($a, $note ?? $stage, 'stage', $stage);
        $this->bus->record('talent.applicant_moved', ['id' => $a->id, 'stage' => $stage], 'applicant', $a->id);

        return $a;
    }

    public function setTalentPool(Applicant $a, bool $in): Applicant
    {
        if ($in && ! $a->consent_at) {
            throw new DomainException('The applicant has not consented to data retention.', 'no-consent');
        }
        $s = $this->settings();
        $a->update(['talent_pool' => $in, 'retain_until' => now()->addMonths($in ? $s->pool_retention_months : $s->applicant_retention_months)->toDateString()]);

        return $a;
    }

    /** Anonymise applicants whose retention ended (not hired). Returns the number anonymised. */
    public function purgeExpired(): int
    {
        $n = 0;
        foreach (Applicant::whereNull('anonymised_at')->whereNotNull('retain_until')->where('retain_until', '<', now()->toDateString())->where('stage', '!=', 'hired')->get() as $a) {
            $this->anonymise($a);
            $n++;
        }

        return $n;
    }

    public function anonymise(Applicant $a): Applicant
    {
        if ($a->cv_path) {
            Storage::disk('local')->delete($a->cv_path);
        }
        ApplicantNote::where('applicant_id', $a->id)->where('kind', 'note')->update(['body' => '[removed]']);
        $a->update(['first_name' => 'Anonymous', 'last_name' => 'Applicant', 'email' => null, 'phone' => null, 'cover_note' => null,
            'cv_path' => null, 'cv_name' => null, 'talent_pool' => false, 'anonymised_at' => now()]);

        return $a;
    }

    // ---- interviews ----------------------------------------------------------------

    public function schedule(Applicant $a, array $d): Interview
    {
        if (in_array($a->stage, ['hired', 'rejected', 'withdrawn'], true) || $a->anonymised_at) {
            throw new DomainException('This applicant is not in an active stage.', 'invalid-state');
        }
        $i = Interview::create([
            'applicant_id' => $a->id, 'scheduled_at' => $d['scheduled_at'], 'location' => $d['location'] ?? null, 'kind' => $d['kind'] ?? 'onsite',
            'panel' => array_values(array_unique(array_map('intval', $d['panel'] ?? []))),
            'criteria' => $d['criteria'] ?? self::DEFAULT_CRITERIA,
        ]);
        if ($a->stage === 'applied' || $a->stage === 'screening') {
            $this->moveStage($a, 'interview', 'Interview scheduled');
        }

        return $i;
    }

    public function score(Interview $i, int $userId, array $scores, string $recommendation, ?string $comment): InterviewScore
    {
        if (! in_array($userId, $i->panel ?? [], true)) {
            throw new DomainException('Only panel members can score this interview.', 'not-on-panel', 403);
        }
        if ($i->status === 'cancelled') {
            throw new DomainException('This interview was cancelled.', 'invalid-state');
        }
        $known = $i->criteria ?? [];
        $clean = [];
        foreach ($scores as $s) {
            if (! in_array($s['criterion'], $known, true)) {
                throw new DomainException('Unknown criterion: '.$s['criterion']);
            }
            $clean[] = ['criterion' => $s['criterion'], 'score' => (int) $s['score']];
        }
        if (! $clean) {
            throw new DomainException('Score at least one criterion.');
        }
        $avg = round(array_sum(array_column($clean, 'score')) / count($clean), 2);

        return InterviewScore::updateOrCreate(['interview_id' => $i->id, 'user_id' => $userId], ['scores' => $clean, 'average' => $avg, 'recommendation' => $recommendation, 'comment' => $comment]);
    }

    /** Scorecard: each panelist's marks, the average per criterion and overall, and the recommendation tally. */
    public function scorecard(Interview $i): array
    {
        $scores = InterviewScore::where('interview_id', $i->id)->get();
        $perCriterion = [];
        foreach ($scores as $s) {
            foreach ($s->scores as $row) {
                $perCriterion[$row['criterion']][] = $row['score'];
            }
        }
        $users = DB::table('users')->whereIn('id', $i->panel ?? [])->pluck('name', 'id');

        return [
            'interview_id' => $i->id,
            'panel' => collect($i->panel)->map(fn ($id) => ['user_id' => $id, 'name' => $users[$id] ?? '#'.$id, 'scored' => $scores->contains('user_id', $id)])->all(),
            'scores' => $scores->map(fn ($s) => ['user_id' => $s->user_id, 'name' => $users[$s->user_id] ?? '', 'scores' => $s->scores, 'average' => $s->average, 'recommendation' => $s->recommendation, 'comment' => $s->comment])->all(),
            'criteria' => collect($perCriterion)->map(fn ($v, $k) => ['criterion' => $k, 'average' => round(array_sum($v) / count($v), 2)])->values()->all(),
            'overall' => $scores->isEmpty() ? null : round($scores->avg('average'), 2),
            'tally' => ['hire' => $scores->where('recommendation', 'hire')->count(), 'maybe' => $scores->where('recommendation', 'maybe')->count(), 'no' => $scores->where('recommendation', 'no')->count()],
        ];
    }

    // ---- offers --------------------------------------------------------------------

    public function createOffer(Applicant $a, array $d): Offer
    {
        if (! in_array($a->stage, ['interview', 'offer', 'screening'], true) || $a->anonymised_at) {
            throw new DomainException('An offer needs an active applicant who has reached screening or interview.', 'invalid-state');
        }
        if (Offer::where('applicant_id', $a->id)->whereIn('status', ['draft', 'sent'])->exists()) {
            throw new DomainException('There is already an open offer for this applicant.', 'duplicate-offer');
        }
        $post = $a->job_post_id ? JobPost::find($a->job_post_id) : null;
        if (($d["contract_kind"] ?? $post?->contract_kind) === "CDD" && empty($d["end_date"])) {
            throw new DomainException("A fixed-term offer needs an end date.");
        }
        $offer = Offer::create([
            'applicant_id' => $a->id, 'job_post_id' => $post?->id, 'department_id' => $d['department_id'] ?? $post?->department_id, 'position_id' => $d['position_id'] ?? $post?->position_id,
            'contract_kind' => $d['contract_kind'] ?? $post?->contract_kind ?? 'CDI', 'base_salary' => $d['base_salary'], 'start_date' => $d['start_date'],
            'end_date' => $d['end_date'] ?? null, 'message' => $d['message'] ?? null, 'created_by' => $this->ctx->actor()?->id,
        ]);
        if ($a->stage !== 'offer') {
            $this->moveStage($a, 'offer', 'Offer prepared');
        }

        return $offer;
    }

    /** Issue the acceptance link and send it to the applicant. */
    public function sendOffer(Offer $o): array
    {
        if (! in_array($o->status, ['draft', 'sent'], true)) {
            throw new DomainException("This offer is {$o->status}.", 'invalid-state');
        }
        $a = Applicant::findOrFail($o->applicant_id);
        $token = Str::random(48);
        $o->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::OFFER_DAYS)]);
        $url = url('/careers/offer/'.$token);
        $this->templates();
        $this->notifier->notify('talent.offer_letter', ['email' => $a->email, 'phone' => $a->phone, 'name' => trim("$a->first_name $a->last_name")],
            ['name' => $a->first_name, 'url' => $url, 'days' => self::OFFER_DAYS], ['type' => 'offer', 'id' => $o->id]);

        return ['url' => $url, 'expires_at' => $o->expires_at];
    }

    public function withdrawOffer(Offer $o): Offer
    {
        if (! in_array($o->status, ['draft', 'sent'], true)) {
            throw new DomainException("This offer is {$o->status}.", 'invalid-state');
        }
        $o->update(['status' => 'withdrawn', 'token_hash' => null]);

        return $o;
    }

    public function offerByToken(string $token): Offer
    {
        $o = $this->ctx->withoutScope(fn () => Offer::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first());
        if (! $o || $o->status !== 'sent') {
            throw new DomainException('This offer link is invalid or no longer open.', 'invalid-link', 404);
        }
        if ($o->expires_at && $o->expires_at->isPast()) {
            $o->update(['status' => 'expired']);
            throw new DomainException('This offer has expired.', 'offer-expired', 410);
        }
        $this->ctx->set($o->group_id, $o->hotel_id, [$o->hotel_id], null);

        return $o;
    }

    public function publicOffer(Offer $o): array
    {
        $a = Applicant::findOrFail($o->applicant_id);
        $post = $o->job_post_id ? JobPost::find($o->job_post_id) : null;

        return ['applicant' => trim("$a->first_name $a->last_name"), 'title' => $post?->title, 'hotel' => Hotel::withoutGlobalScopes()->find($o->hotel_id)?->name,
            'contract_kind' => $o->contract_kind, 'base_salary' => $o->base_salary, 'start_date' => $o->start_date->toDateString(), 'end_date' => $o->end_date?->toDateString(),
            'message' => $o->message, 'expires_at' => $o->expires_at];
    }

    public function decline(Offer $o): Offer
    {
        $o->update(['status' => 'declined', 'decided_at' => now(), 'token_hash' => null]);
        $a = Applicant::findOrFail($o->applicant_id);
        $a->update(['stage' => 'withdrawn', 'retain_until' => now()->addMonths($this->settings()->applicant_retention_months)->toDateString()]);
        $this->addNote($a, 'Offer declined by the applicant', 'stage', 'withdrawn');

        return $o;
    }

    /** Acceptance creates the employee with a contract, then drafts and sends the Q2 e-contract for signature. */
    public function accept(Offer $o): Offer
    {
        if ($o->status !== 'sent') {
            throw new DomainException("This offer is {$o->status}.", 'invalid-state');
        }
        $a = Applicant::findOrFail($o->applicant_id);

        return DB::transaction(function () use ($o, $a) {
            $e = $this->hr->hire([
                'first_name' => $a->first_name, 'last_name' => $a->last_name, 'email' => $a->email, 'phone' => $a->phone,
                'department_id' => $o->department_id, 'position_id' => $o->position_id, 'hire_date' => $o->start_date->toDateString(),
                'contract' => ['kind' => $o->contract_kind, 'start_date' => $o->start_date->toDateString(), 'end_date' => $o->end_date?->toDateString(),
                    'base_salary' => $o->base_salary, 'hours_per_week' => 40],
            ]);
            $doc = $this->contracts->generate($e, $o->contract_kind);
            $o->update(['status' => 'accepted', 'decided_at' => now(), 'token_hash' => null, 'employee_id' => $e->id, 'econtract_id' => $doc->id]);
            $a->update(['stage' => 'hired', 'employee_id' => $e->id, 'retain_until' => null, 'talent_pool' => false]);
            $this->addNote($a, 'Offer accepted; employee '.$e->number.' created', 'stage', 'hired');
            try {
                $this->contracts->send($doc);
            } catch (\Throwable) {
                // The contract stays a draft that HR can send from the lifecycle screen.
            }
            $this->bus->record('talent.offer_accepted', ['id' => $o->id, 'employee_id' => $e->id, 'econtract_id' => $doc->id], 'offer', $o->id);

            return $o;
        });
    }

    /** Hotel-level default message templates (created once per hotel). */
    public function templates(): void
    {
        $rows = [
            ['talent.offer_letter', 'email', 'fr', 'Votre offre d\'emploi', "Bonjour {{name}},\n\nNous avons le plaisir de vous adresser une offre d'emploi. Consultez-la et répondez ici : {{url}}\nCe lien est valable {{days}} jours."],
            ['talent.offer_letter', 'email', 'en', 'Your job offer', "Hello {{name}},\n\nWe are pleased to send you a job offer. Review it and reply here: {{url}}\nThe link is valid for {{days}} days."],
            ['talent.offer_letter', 'sms', 'fr', null, '{{hotel_name}} : votre offre d\'emploi est disponible : {{url}}'],
            ['talent.offer_letter', 'sms', 'en', null, '{{hotel_name}}: your job offer is ready: {{url}}'],
        ];
        foreach ($rows as [$event, $channel, $locale, $subject, $body]) {
            NotificationTemplate::firstOrCreate(['event' => $event, 'channel' => $channel, 'locale' => $locale], ['category' => 'transactional', 'subject' => $subject, 'body' => $body]);
        }
    }

    public function cvPath(Applicant $a): ?string
    {
        return $a->cv_path && Storage::disk('local')->exists($a->cv_path) ? Storage::disk('local')->path($a->cv_path) : null;
    }
}

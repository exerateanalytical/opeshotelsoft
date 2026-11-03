<?php

namespace App\Modules\Insights\Http;

use App\Modules\Insights\Application\AlertService;
use App\Modules\Insights\Application\DigestService;
use App\Modules\Insights\Application\Evaluator;
use App\Modules\Insights\Application\OwnerMetrics;
use App\Modules\Insights\Application\Rules;
use App\Modules\Insights\Domain\Alert;
use App\Modules\Insights\Domain\Digest;
use App\Modules\Insights\Domain\Setting;
use App\Platform\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InsightsController
{
    public function __construct(private TenantContext $ctx, private AlertService $alerts, private OwnerMetrics $metrics) {}

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        $ok = match ($ability) {
            'view' => $u->can('insights.view') || $u->can('insights.manage'),
            'group' => $u->can('insights.group'),
            default => $u->can('insights.manage'),
        };
        abort_unless($ok, 403);
    }

    private function date(Request $request): string
    {
        if ($d = $request->query('date')) {
            return Carbon::parse($d)->toDateString();
        }
        $bd = DB::table('hotels')->where('id', $this->ctx->requireHotelId())->value('business_date');

        return Carbon::parse($bd ?? now())->toDateString();
    }

    /** Owner page payload for the active hotel: KPIs for the day plus the previous day for comparison. */
    public function owner(Request $request)
    {
        $this->can($request, 'view');
        $request->validate(['date' => ['nullable', 'date']]);
        $hotelId = $this->ctx->requireHotelId();
        $date = $this->date($request);

        return [
            'today' => $this->metrics->forHotel($hotelId, $date),
            'yesterday' => $this->metrics->forHotel($hotelId, Carbon::parse($date)->subDay()->toDateString()),
        ];
    }

    /** All hotels of the group the user may see (no X-Hotel-Id needed). */
    public function group(Request $request)
    {
        $this->can($request, 'group');
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->query('date') ? Carbon::parse($request->query('date'))->toDateString() : '';

        return $this->metrics->group($date, $request->user()->allowedHotelIds());
    }

    public function alerts(Request $request)
    {
        $this->can($request, 'view');
        $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved', 'active'])],
            'rule' => ['nullable', Rule::in(array_keys(Rules::ALL))], 'severity' => ['nullable', Rule::in(['low', 'medium', 'high'])],
        ]);

        return Alert::query()
            ->when($request->query('status'), fn ($q, $s) => $s === 'active' ? $q->whereIn('status', ['open', 'acknowledged']) : $q->where('status', $s))
            ->when($request->query('rule'), fn ($q, $r) => $q->where('rule', $r))
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 50), 1), 200));
    }

    public function show(Request $request, Alert $alert)
    {
        $this->can($request, 'view');

        return $alert->load(['hotel:id,code,name']);
    }

    public function acknowledge(Request $request, Alert $alert)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->alerts->acknowledge($alert, $data['note'] ?? null);
    }

    public function resolve(Request $request, Alert $alert)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->alerts->resolve($alert, $data['note']);
    }

    public function settings(Request $request)
    {
        $this->can($request, 'view');
        $s = Setting::first();

        return [
            'rules' => Rules::forHotel($s),
            'digest_enabled' => (bool) $s?->digest_enabled, 'digest_emails' => $s?->digest_emails ?? [],
            'digest_whatsapp' => $s?->digest_whatsapp ?? [], 'digest_locale' => $s?->digest_locale ?? 'fr',
        ];
    }

    public function updateSettings(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'rules' => ['sometimes', 'array'],
            'rules.*' => ['array'],
            'rules.*.enabled' => ['sometimes', 'boolean'],
            'rules.*.min_amount' => ['sometimes', 'integer', 'min:0'],
            'rules.*.floor_amount' => ['sometimes', 'integer', 'min:0'],
            'rules.*.percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'rules.*.grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'rules.*.start' => ['sometimes', 'date_format:H:i'],
            'rules.*.end' => ['sometimes', 'date_format:H:i'],
            'digest_enabled' => ['sometimes', 'boolean'],
            'digest_emails' => ['sometimes', 'array', 'max:10'], 'digest_emails.*' => ['email'],
            'digest_whatsapp' => ['sometimes', 'array', 'max:10'], 'digest_whatsapp.*' => ['string', 'regex:/^\+?[0-9 ]{8,16}$/'],
            'digest_locale' => ['sometimes', Rule::in(['fr', 'en'])],
        ]);
        abort_if(array_diff_key($data['rules'] ?? [], Rules::ALL) !== [], 422, 'Unknown rule.');

        $s = Setting::firstOrNew();
        $rules = $s->rules ?? [];
        foreach ($data['rules'] ?? [] as $rule => $cfg) {
            $rules[$rule] = array_merge($rules[$rule] ?? [], array_intersect_key($cfg, Rules::ALL[$rule]));
        }
        $s->fill(['rules' => $rules] + array_intersect_key($data, array_flip(['digest_enabled', 'digest_emails', 'digest_whatsapp', 'digest_locale'])));
        $s->save();

        return $this->settings($request);
    }

    public function scan(Request $request, Evaluator $evaluator)
    {
        $this->can($request, 'manage');
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->input('date') ? Carbon::parse($request->input('date'))->toDateString() : $this->date($request);

        return ['date' => $date, 'findings' => $evaluator->scan($this->ctx->requireHotelId(), $date)];
    }

    public function sendDigest(Request $request, DigestService $digests)
    {
        $this->can($request, 'manage');
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->input('date') ? Carbon::parse($request->input('date'))->toDateString() : $this->date($request);
        $d = $digests->send($date, force: true);
        abort_unless($d, 422, 'The owner digest is off or has no recipients.');

        return $d;
    }

    public function digests(Request $request)
    {
        $this->can($request, 'view');

        return Digest::orderByDesc('date')->limit(30)->get(['id', 'date', 'messages', 'sent_at']);
    }
}

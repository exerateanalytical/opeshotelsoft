<?php

namespace App\Modules\ServiceRequests\Http;

use App\Models\User;
use App\Modules\Property\Domain\Room;
use App\Modules\ServiceRequests\Application\Catalog;
use App\Modules\ServiceRequests\Application\EscalationService;
use App\Modules\ServiceRequests\Application\ServiceRequestService;
use App\Modules\ServiceRequests\Application\SpokenText;
use App\Modules\ServiceRequests\Application\VoicePrefs;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use App\Modules\ServiceRequests\Domain\Setting;
use App\Modules\ServiceRequests\Domain\VoiceEvent;
use App\Modules\Tenancy\Application\StaffDirectory;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceRequestController
{
    /** Events older than this are never spoken (a device that was asleep must not replay the morning). */
    private const FEED_WINDOW_MINUTES = 10;

    public function __construct(private ServiceRequestService $service, private TenantContext $ctx) {}

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        $ok = match ($ability) {
            'view' => $u->can('servicerequests.view') || $u->can('servicerequests.manage'),
            'voice' => $u->can('servicerequests.voice'),
            'settings' => $u->can('servicerequests.settings'),
            'feed' => $u->can('servicerequests.voice') || $u->can('servicerequests.view') || $u->can('servicerequests.manage'),
            default => $u->can('servicerequests.manage'),
        };
        abort_unless($ok, 403);
    }

    public function index(Request $request)
    {
        $this->can($request, 'view');
        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'done', 'cancelled', 'all', ...array_keys(['new' => 1, 'acknowledged' => 1, 'in_progress' => 1])])],
            'department' => ['nullable', Rule::in(Catalog::DEPARTMENTS)],
            'q' => ['nullable', 'string', 'max:30'],
        ]);
        $status = $request->query('status', 'active');
        $q = ServiceRequest::with('assignee')
            ->when($request->query('department'), fn ($b, $d) => $b->where('department', $d))
            ->when($request->query('q'), fn ($b, $t) => $b->where('room_number', 'like', $t.'%'));
        match ($status) {
            'active' => $q->whereIn('status', ServiceRequest::OPEN)->orderBy('due_at'),
            'done' => $q->whereIn('status', ['done', 'cancelled'])->where('updated_at', '>=', now()->subDay())->orderByDesc('updated_at'),
            'all' => $q->orderByDesc('id'),
            default => $q->where('status', $status)->orderBy('due_at'),
        };

        return ['data' => $q->limit(300)->get(), 'server_time' => now()->toIso8601String()];
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'room_number' => ['required', 'string', 'max:20'],
            'category' => ['required', Rule::in(array_keys(Catalog::CATEGORIES))],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*' => ['nullable'],
            'note' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', Rule::in(Catalog::PRIORITIES)],
            'source' => ['nullable', Rule::in(['reception', 'room_phone', 'staff'])],
        ]);
        abort_unless(Room::where('number', trim($data['room_number']))->exists(), 422, __('servicerequests.error.unknown_room'));

        $r = $this->service->create($data + ['hotel_id' => $this->ctx->requireHotelId(), 'source' => $data['source'] ?? 'reception']);

        return response()->json($r->load('assignee'), 201);
    }

    public function show(Request $request, ServiceRequest $serviceRequest)
    {
        $this->can($request, 'view');

        return $serviceRequest->load('assignee');
    }

    public function acknowledge(Request $request, ServiceRequest $serviceRequest)
    {
        $this->can($request, 'manage');

        return $this->service->acknowledge($serviceRequest)->load('assignee');
    }

    public function assign(Request $request, ServiceRequest $serviceRequest)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        abort_unless(StaffDirectory::query((int) $this->ctx->groupId(), $this->ctx->requireHotelId())->whereKey($data['user_id'])->exists(), 422, __('servicerequests.error.unknown_user'));

        return $this->service->assign($serviceRequest, (int) $data['user_id'])->load('assignee');
    }

    public function start(Request $request, ServiceRequest $serviceRequest)
    {
        $this->can($request, 'manage');

        return $this->service->start($serviceRequest)->load('assignee');
    }

    public function complete(Request $request, ServiceRequest $serviceRequest)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->service->complete($serviceRequest, $data['note'] ?? null)->load('assignee');
    }

    public function cancel(Request $request, ServiceRequest $serviceRequest)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->service->cancel($serviceRequest, $data['note'] ?? null)->load('assignee');
    }

    /** Categories, departments, items, hotel SLA and the staff the board can assign to. */
    public function catalog(Request $request)
    {
        $this->can($request, 'view');
        $sla = Catalog::sla();

        return [
            'categories' => collect(Catalog::CATEGORIES)->map(fn ($c, $k) => ['code' => $k, 'department' => $c[0], 'sla_minutes' => $sla[$k]])->values(),
            'departments' => Catalog::DEPARTMENTS,
            'items' => collect(Catalog::ITEMS)->map(fn ($i, $k) => ['code' => $k, 'category' => $i[0], 'fr' => $i[1], 'en' => $i[2]])->values(),
            'staff' => StaffDirectory::query((int) $this->ctx->groupId(), $this->ctx->requireHotelId())->orderBy('name')->limit(300)->get(['id', 'name']),
        ];
    }

    public function settings(Request $request)
    {
        $this->can($request, 'view');

        return [
            'sla' => Catalog::sla(), 'defaults' => Catalog::defaultSla(),
            'voice_messages' => (bool) (Setting::first()?->voice_messages ?? true),
        ];
    }

    public function updateSettings(Request $request)
    {
        $this->can($request, 'settings');
        $data = $request->validate([
            'sla' => ['sometimes', 'array'],
            'sla.*' => ['integer', 'min:1', 'max:1440'],
            'voice_messages' => ['sometimes', 'boolean'],
        ]);
        abort_if(array_diff_key($data['sla'] ?? [], Catalog::CATEGORIES) !== [], 422, 'Unknown category.');
        $s = Setting::firstOrNew();
        $s->sla = array_merge($s->sla ?? [], $data['sla'] ?? []);
        if (isset($data['voice_messages'])) {
            $s->voice_messages = $data['voice_messages'];
        }
        $s->save();

        return $this->settings($request);
    }

    public function voiceSettings(Request $request)
    {
        $this->can($request, 'voice');

        return ['settings' => VoicePrefs::for($request->user()), 'departments' => Catalog::DEPARTMENTS, 'locale' => $this->locale($request)];
    }

    public function updateVoiceSettings(Request $request)
    {
        $this->can($request, 'voice');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'departments' => ['sometimes', 'array'], 'departments.*' => [Rule::in(Catalog::DEPARTMENTS)],
            'repeat_seconds' => ['sometimes', 'integer', Rule::in([0, 30, 60, 120, 300])],
            'quiet_enabled' => ['sometimes', 'boolean'],
            'quiet_start' => ['sometimes', 'date_format:H:i'], 'quiet_end' => ['sometimes', 'date_format:H:i'],
            'volume' => ['sometimes', 'numeric', 'between:0,1'],
            'rate' => ['sometimes', 'numeric', 'between:0.5,1.5'],
            'voice' => ['sometimes', 'nullable', 'string', 'max:120'],
            'chime' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('voice', $data)) {
            $data['voice'] = (string) $data['voice'];
        }

        return ['settings' => VoicePrefs::save($request->user(), $data), 'departments' => Catalog::DEPARTMENTS, 'locale' => $this->locale($request)];
    }

    private function locale(Request $request): string
    {
        $l = $request->query('locale', $request->user()->locale);

        return str_starts_with((string) $l, 'en') ? 'en' : 'fr';
    }

    /**
     * The voice engine's transport: events after the cursor for this user's departments, with the text to speak
     * in the user's language, plus the requests still unacknowledged (for "repeat until acknowledged").
     * Without `since` it only returns the cursor to start from.
     */
    public function feed(Request $request)
    {
        $this->can($request, 'feed');
        $request->validate(['since' => ['nullable', 'integer', 'min:0'], 'locale' => ['nullable', Rule::in(['fr', 'en'])]]);
        /** @var User $user */
        $user = $request->user();
        $locale = $this->locale($request);
        $prefs = VoicePrefs::for($user);
        $manager = $user->hasAnyRole(EscalationService::managerRoles('front_office')) && $user->can('servicerequests.manage');
        $latest = (int) VoiceEvent::max('id');

        $out = ['events' => [], 'cursor' => $latest, 'pending' => [], 'locale' => $locale, 'server_time' => now()->toIso8601String()];
        $since = $request->query('since');
        if ($since !== null) {
            $events = VoiceEvent::where('id', '>', (int) $since)
                ->where('created_at', '>=', now()->subMinutes(self::FEED_WINDOW_MINUTES))
                ->where(fn ($q) => $q->whereIn('department', $prefs['departments'])->when($manager, fn ($q) => $q->orWhere('kind', 'overdue')))
                ->orderBy('id')->limit(50)->get();
            $status = ServiceRequest::whereIn('id', $events->pluck('service_request_id')->filter())->pluck('status', 'id');
            $out['events'] = $events->map(fn (VoiceEvent $e) => $this->present($e, $locale, $status[$e->service_request_id] ?? null))->values();
            $out['cursor'] = $events->count() === 50 ? (int) $events->last()->id : max($latest, (int) $since);
        }

        $out['pending'] = ServiceRequest::where('status', 'new')->whereIn('department', $prefs['departments'])
            ->orderBy('id')->limit(20)->get()
            ->map(fn (ServiceRequest $r) => ['request_id' => $r->id, 'room_number' => $r->room_number, 'priority' => $r->priority]
                + SpokenText::build($locale, ['kind' => $r->escalated_at ? 'late' : 'request', 'room_number' => $r->room_number, 'category' => $r->category, 'items' => $r->items, 'note' => $r->note, 'priority' => $r->priority]))
            ->values();

        return $out;
    }

    private function present(VoiceEvent $e, string $locale, ?string $status): array
    {
        $p = $e->payload ?? [];
        $minutesLate = (int) ($p['minutes_late'] ?? 0);
        $spoken = SpokenText::build($locale, [
            'kind' => $e->kind, 'room_number' => $e->room_number, 'category' => $p['category'] ?? 'other', 'items' => $p['items'] ?? [],
            'note' => $p['note'] ?? null, 'priority' => $p['priority'] ?? 'normal', 'minutes_late' => $minutesLate,
        ]);

        return [
            'id' => $e->id, 'kind' => $e->kind, 'request_id' => $e->service_request_id, 'room_number' => $e->room_number,
            'department' => $e->department, 'category' => $p['category'] ?? null, 'priority' => $p['priority'] ?? 'normal',
            'status' => $status, 'created_at' => $e->created_at?->toIso8601String(),
        ] + $spoken;
    }

    public function escalate(Request $request, EscalationService $escalation)
    {
        $this->can($request, 'manage');
        $this->ctx->requireHotelId();

        return $escalation->run();
    }
}

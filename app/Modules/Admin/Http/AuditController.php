<?php

namespace App\Modules\Admin\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/** Audit log (spatie activitylog), limited to actions by users of the caller's group. */
class AuditController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $f = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return $this->base($request)
            ->with('causer:id,name,email')
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('causer_id', $v))
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', now()->parse($v)->addDay()->toDateString()))
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 50);
    }

    /** Filter choices: users of the group, subject types and events present in the log. */
    public function filters(Request $request)
    {
        abort_unless($request->user()->can('audit.view'), 403);

        return [
            'users' => User::where('group_id', $request->user()->group_id)->orderBy('name')->get(['id', 'name']),
            'subject_types' => $this->base($request)->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'events' => $this->base($request)->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
        ];
    }

    private function base(Request $request)
    {
        return Activity::query()
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', User::where('group_id', $request->user()->group_id)->select('id'));
    }
}

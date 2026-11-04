<?php

namespace App\Modules\Reports\TaskHome;

use App\Models\User;
use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Workforce\Domain\RosterShift;
use App\Modules\Housekeeping\Domain\HkTask;
use App\Modules\Maintenance\Domain\WorkOrder;
use App\Modules\People\Domain\Task;
use App\Modules\Reservations\Revenue\Domain\RateSuggestion;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Read-side aggregator for the staff task home (/tasks): "my work" first, for any role.
 * Every section is gated by the permissions the owning module already uses; rows carry
 * can_* flags so the page only offers one-tap actions the module's own API will accept.
 * Actions themselves are never performed here: the page calls the owning modules' endpoints.
 */
class TaskHomeService
{
    /** A task is "due soon" when due within this many minutes. */
    public const SOON_MINUTES = 120;

    /** Service-request departments a permission makes me responsible for. */
    private const DEPT_BY_PERMISSION = ['housekeeping.view' => 'housekeeping', 'maintenance.view' => 'maintenance', 'pos.view' => 'fnb', 'frontdesk.view' => 'front_office'];

    public function __construct(private TenantContext $ctx) {}

    public function forUser(User $user): array
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $me = Employee::where('user_id', $user->id)->first();
        $sections = [];

        $sections['people_tasks'] = $this->peopleTasks($me);
        if ($user->can('servicerequests.view')) {
            $sections['service_requests'] = $this->serviceRequests($user);
            if ($user->can('servicerequests.manage') && $user->can('servicerequests.settings')) {
                $sections['escalations'] = $this->escalations();
            }
        }
        if ($user->can('housekeeping.view')) {
            $sections['housekeeping'] = $this->housekeeping($user, $hotel);
        }
        if ($user->can('maintenance.view')) {
            $sections['work_orders'] = $this->workOrders($user);
        }
        if ($user->can('hr.manage') || $user->can('revenue.approve')) {
            $sections['approvals'] = $this->approvals($user);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'business_date' => $hotel->business_date->toDateString(),
            'me' => $me ? ['id' => $me->id, 'name' => trim($me->first_name.' '.$me->last_name), 'department_id' => $me->department_id] : null,
            'shift' => $me ? $this->shift($me, $hotel) : null,
            'sections' => $sections,
            'counts' => collect($sections)->map(fn ($rows) => count($rows))->all(),
            'overdue_total' => collect($sections)->flatten(1)->where('urgency', 'overdue')->count(),
        ];
    }

    /** overdue | due_soon | today (no deadline or later today). */
    public function urgency(?Carbon $due): string
    {
        if (! $due) {
            return 'today';
        }

        return $due->isPast() ? 'overdue' : ($due->diffInMinutes(now()) <= self::SOON_MINUTES ? 'due_soon' : 'today');
    }

    private function peopleTasks(?Employee $me): array
    {
        if (! $me) {
            return [];
        }

        return Task::whereIn('status', ['open', 'in_progress'])
            ->where(fn ($w) => $w->where('assignee_employee_id', $me->id)
                ->orWhere(fn ($d) => $d->whereNull('assignee_employee_id')->where('department_id', $me->department_id ?? 0)))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderBy('due_at')->limit(50)->get()
            ->map(fn (Task $t) => [
                'kind' => 'people_task', 'id' => $t->id, 'title' => $t->title, 'subtitle' => $t->description ? mb_substr($t->description, 0, 90) : null,
                'priority' => $t->priority, 'status' => $t->status, 'due_at' => $t->due_at?->toIso8601String(), 'urgency' => $this->urgency($t->due_at),
                'can_start' => $t->status === 'open', 'can_complete' => $t->status === 'in_progress' && ! $t->requires_photo && empty($t->checklist),
                'detail_url' => '/people/tasks', 'requires_photo' => (bool) $t->requires_photo,
            ])->all();
    }

    private function serviceRequests(User $user): array
    {
        $depts = [];
        foreach (self::DEPT_BY_PERMISSION as $perm => $dept) {
            if ($user->can($perm)) {
                $depts[] = $dept;
            }
        }
        $manage = $user->can('servicerequests.manage');

        return ServiceRequest::whereIn('status', ServiceRequest::OPEN)
            ->where(fn ($w) => $w->where('assigned_to', $user->id)
                ->orWhere(fn ($d) => $d->whereNull('assigned_to')->whereIn('department', $depts ?: ['-'])))
            ->orderBy('due_at')->limit(50)->get()
            ->map(fn (ServiceRequest $r) => [
                'kind' => 'service_request', 'id' => $r->id, 'title' => $r->category.($r->room_number ? ' · '.$r->room_number : ''),
                'subtitle' => $r->note ?: (collect($r->items ?? [])->pluck('name')->filter()->implode(', ') ?: null), 'room' => $r->room_number,
                'department' => $r->department, 'priority' => $r->priority, 'status' => $r->status, 'due_at' => $r->due_at?->toIso8601String(),
                'urgency' => $this->urgency($r->due_at), 'mine' => $r->assigned_to === $user->id, 'escalated' => (bool) $r->escalated_at,
                'can_acknowledge' => $manage && $r->status === 'new', 'can_start' => $manage && in_array($r->status, ['new', 'acknowledged'], true),
                'can_complete' => $manage && in_array($r->status, ['acknowledged', 'in_progress'], true), 'detail_url' => '/service-requests',
            ])->all();
    }

    /** Late or escalated requests, for supervisors. */
    private function escalations(): array
    {
        return ServiceRequest::whereIn('status', ServiceRequest::OPEN)
            ->where(fn ($w) => $w->whereNotNull('escalated_at')->orWhere('due_at', '<', now()))
            ->orderBy('due_at')->limit(30)->get()
            ->map(fn (ServiceRequest $r) => [
                'kind' => 'escalation', 'id' => $r->id, 'title' => $r->category.($r->room_number ? ' · '.$r->room_number : ''), 'subtitle' => $r->department,
                'priority' => $r->priority, 'status' => $r->status, 'due_at' => $r->due_at?->toIso8601String(), 'urgency' => 'overdue',
                'escalated' => (bool) $r->escalated_at, 'detail_url' => '/service-requests',
            ])->all();
    }

    private function housekeeping(User $user, Hotel $hotel): array
    {
        $q = HkTask::with('room:id,number,floor')->whereDate('date', $hotel->business_date->toDateString())->whereIn('status', ['pending', 'in_progress', 'done', 'failed']);
        $manage = $user->can('housekeeping.manage');
        if (! $manage) {
            $q->where('assigned_to', $user->id);
        } else {
            $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereIn('status', ['done', 'failed']));
        }

        return $q->orderBy('priority')->orderBy('id')->limit(60)->get()->map(function (HkTask $t) use ($user, $manage) {
            $mine = $t->assigned_to === $user->id;
            $review = in_array($t->status, ['done', 'failed'], true);

            return [
                'kind' => 'housekeeping', 'id' => $t->id, 'title' => ($t->room?->number ?? '—').' · '.$t->kind, 'subtitle' => $t->room?->floor !== null ? 'floor '.$t->room->floor : null,
                'room' => $t->room?->number, 'priority' => $t->priority <= 1 ? 'high' : 'normal', 'status' => $t->status, 'due_at' => null,
                'urgency' => $review ? 'today' : ($t->priority <= 1 ? 'due_soon' : 'today'),
                'can_start' => $mine && $t->status === 'pending', 'can_complete' => $mine && $t->status === 'in_progress',
                'needs_inspection' => $manage && $t->status === 'done', 'detail_url' => '/housekeeping',
            ];
        })->all();
    }

    private function workOrders(User $user): array
    {
        $manage = $user->can('maintenance.manage');
        $q = WorkOrder::with('room:id,number')->whereNotIn('status', ['done', 'completed', 'cancelled', 'closed'])
            ->where(fn ($w) => $w->where('assigned_to', $user->id)->when($manage, fn ($x) => $x->orWhereNull('assigned_to')));

        return $q->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderBy('due_at')->limit(50)->get()
            ->map(function (WorkOrder $w) use ($user, $manage) {
                $u = $this->urgency($w->due_at);

                return [
                    'kind' => 'work_order', 'id' => $w->id, 'title' => $w->title, 'subtitle' => trim(($w->number ?? '').' '.($w->room?->number ? '· '.$w->room->number : '')),
                    'room' => $w->room?->number, 'priority' => $w->priority, 'status' => $w->status, 'due_at' => $w->due_at?->toIso8601String(),
                    'urgency' => $u === 'today' && in_array($w->priority, ['urgent', 'high'], true) ? 'due_soon' : $u,
                    'mine' => $w->assigned_to === $user->id, 'can_start' => $manage && in_array($w->status, ['open', 'assigned'], true),
                    'can_complete' => false, 'detail_url' => '/maintenance',
                ];
            })->all();
    }

    /** Supervisor approvals the user may decide: leave, price suggestions. */
    private function approvals(User $user): array
    {
        $rows = [];
        if ($user->can('hr.manage')) {
            foreach (LeaveRequest::with('employee:id,first_name,last_name')->where('status', 'pending')->orderBy('start_date')->limit(20)->get() as $l) {
                $rows[] = [
                    'kind' => 'leave', 'id' => $l->id, 'title' => trim(($l->employee?->first_name ?? '').' '.($l->employee?->last_name ?? '')),
                    'subtitle' => $l->start_date->toDateString().' → '.$l->end_date->toDateString().' ('.$l->days.')', 'priority' => 'normal', 'status' => 'pending',
                    'due_at' => $l->start_date->toIso8601String(), 'urgency' => $l->start_date->lt(now()->startOfDay()) ? 'overdue' : ($l->start_date->lte(now()->addDays(2)) ? 'due_soon' : 'today'), 'can_approve' => true, 'detail_url' => '/hr',
                ];
            }
        }
        if ($user->can('revenue.approve')) {
            foreach (RateSuggestion::where('status', 'pending')->orderBy('date')->limit(20)->get() as $s) {
                $rows[] = [
                    'kind' => 'price_suggestion', 'id' => $s->id, 'title' => Carbon::parse($s->date)->toDateString(), 'subtitle' => $s->current_rate.' → '.$s->suggested_rate.' ('.$s->change_pct.'%)',
                    'priority' => 'normal', 'status' => 'pending', 'due_at' => null, 'urgency' => Carbon::parse($s->date)->lte(now()->addDays(2)) ? 'due_soon' : 'today', 'can_approve' => true, 'detail_url' => '/revenue/suggestions',
                ];
            }
        }

        return $rows;
    }

    private function shift(Employee $me, Hotel $hotel): array
    {
        $today = RosterShift::where('employee_id', $me->id)->whereDate('date', $hotel->business_date->toDateString())
            ->whereHas('roster', fn ($q) => $q->where('status', 'published'))->orderBy('starts_at')->get();
        $open = Attendance::where('employee_id', $me->id)->whereNull('clock_out')->latest('clock_in')->first();

        return [
            'shifts' => $today->map(fn ($s) => ['starts_at' => (string) $s->starts_at, 'ends_at' => (string) $s->ends_at, 'role' => $s->role])->all(),
            'clocked_in' => (bool) $open, 'since' => $open?->clock_in ? Carbon::parse($open->clock_in)->toIso8601String() : null,
        ];
    }
}

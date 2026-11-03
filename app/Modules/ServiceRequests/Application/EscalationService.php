<?php

namespace App\Modules\ServiceRequests\Application;

use App\Models\User;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use App\Modules\ServiceRequests\Domain\VoiceEvent;
use App\Modules\Tenancy\Application\StaffDirectory;
use App\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * Scan for requests that nobody picked up. For the hotel in context:
 *  - still "new" at half the SLA: announce again as late ("En retard");
 *  - not done at the SLA: manager alert (voice + email through Notifications), once.
 */
class EscalationService
{
    public const EVENT = 'servicerequests.overdue';

    /** Roles that act as managers for each department (always plus the general manager and owners). */
    private const MANAGER_ROLES = [
        'housekeeping' => ['housekeeping-manager'], 'maintenance' => [], 'fnb' => ['fnb-manager'], 'front_office' => ['front-office-manager'],
    ];

    private const ALWAYS = ['general-manager', 'group-owner', 'hotel-owner'];

    public function __construct(private TenantContext $ctx, private Notifier $notifier) {}

    public static function managerRoles(string $department): array
    {
        return array_merge(self::ALWAYS, self::MANAGER_ROLES[$department] ?? []);
    }

    /** @return array{late:int, overdue:int} */
    public function run(): array
    {
        $late = $overdue = 0;
        $now = now();

        foreach (ServiceRequest::where('status', 'new')->whereNull('escalated_at')->get() as $r) {
            $half = $r->created_at->copy()->addSeconds((int) ($r->created_at->diffInSeconds($r->due_at, false) / 2));
            if ($now->lt($half)) {
                continue;
            }
            $r->forceFill(['escalated_at' => $now])->save();
            $this->voice('late', $r);
            $late++;
        }

        foreach (ServiceRequest::whereIn('status', ServiceRequest::OPEN)->whereNull('overdue_at')->where('due_at', '<=', $now)->get() as $r) {
            $minutes = (int) $r->due_at->diffInMinutes($now);
            $r->forceFill(['overdue_at' => $now])->save();
            $this->voice('overdue', $r, ['minutes_late' => $minutes]);
            $this->alertManagers($r, $minutes);
            $overdue++;
        }

        return ['late' => $late, 'overdue' => $overdue];
    }

    private function voice(string $kind, ServiceRequest $r, array $extra = []): void
    {
        VoiceEvent::create([
            'kind' => $kind, 'service_request_id' => $r->id, 'room_number' => $r->room_number, 'department' => $r->department,
            'payload' => ['category' => $r->category, 'items' => $r->items, 'note' => $r->note, 'priority' => $r->priority, 'source' => $r->source] + $extra,
        ]);
    }

    private function alertManagers(ServiceRequest $r, int $minutes): void
    {
        $this->ensureTemplates();
        $users = StaffDirectory::query((int) $this->ctx->groupId(), (int) $this->ctx->hotelId())
            ->whereNotNull('email')->get()
            ->filter(fn (User $u) => $u->hasAnyRole(self::managerRoles($r->department)));
        foreach ($users as $u) {
            try {
                $this->notifier->notify(self::EVENT, ['email' => $u->email, 'name' => $u->name, 'locale' => $u->locale], [
                    'room' => $r->room_number, 'category' => $r->category, 'department' => $r->department, 'minutes' => $minutes,
                    'what' => SpokenText::build($u->locale === 'en' ? 'en' : 'fr', ['room_number' => $r->room_number, 'category' => $r->category, 'items' => $r->items, 'kind' => 'request'])['text'],
                ], ['type' => 'service_request', 'id' => $r->id]);
            } catch (Throwable $e) {
                report($e); // one bad mailbox never blocks the scan
            }
        }
    }

    /** Hotel-level FR/EN email templates for the manager alert (editable in the Notifications screens). */
    private function ensureTemplates(): void
    {
        $tpl = [
            'fr' => ['Demande en retard : chambre {{room}}', "Bonjour {{guest_name}},\n\nLa demande suivante n'est pas traitée dans le délai prévu ({{minutes}} min de retard).\n{{what}}\n\n{{hotel_name}}"],
            'en' => ['Overdue request: room {{room}}', "Hello {{guest_name}},\n\nThe following request was not completed within its deadline ({{minutes}} min overdue).\n{{what}}\n\n{{hotel_name}}"],
        ];
        foreach ($tpl as $locale => [$subject, $body]) {
            NotificationTemplate::firstOrCreate(
                ['event' => self::EVENT, 'channel' => 'email', 'locale' => $locale],
                ['category' => 'transactional', 'subject' => $subject, 'body' => $body],
            );
        }
    }
}

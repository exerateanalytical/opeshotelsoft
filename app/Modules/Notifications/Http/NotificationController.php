<?php

namespace App\Modules\Notifications\Http;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Application\DefaultTemplates;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController
{
    public const SAMPLE = [
        'guest_name' => 'Jean Mballa', 'number' => 'RES-000123', 'booking_ref' => 'AB12CD', 'arrival' => '2026-12-20',
        'departure' => '2026-12-23', 'total_amount' => '150 000', 'total' => '150 000', 'currency' => 'XAF',
        'hotel_name' => 'Hôtel Akwa', 'hotel_phone' => '+237 600 00 00 00', 'check_in_time' => '14:00', 'code' => '482913',
        'minutes' => 10, 'name' => 'Awa', 'url' => 'https://example.cm/reset/xyz', 'title' => 'Offre spéciale', 'message' => '-20% ce week-end',
    ];

    public function __construct(private TenantContext $ctx) {}

    public function templates(Request $request)
    {
        $this->can($request, 'view');
        $this->ctx->requireHotelId();
        DefaultTemplates::ensureForCurrentHotel();

        return [
            'events' => collect(DefaultTemplates::all())->map(fn ($d, $event) => ['event' => $event, 'category' => $d[0]])->values(),
            'templates' => NotificationTemplate::orderBy('event')->orderBy('channel')->orderBy('locale')
                ->when($request->query('event'), fn ($q, $v) => $q->where('event', $v))
                ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
                ->when($request->query('locale'), fn ($q, $v) => $q->where('locale', $v))->get(),
        ];
    }

    public function updateTemplate(Request $request, int $id)
    {
        $this->can($request, 'manage');
        $t = NotificationTemplate::findOrFail($id);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'], 'body' => ['sometimes', 'string', 'max:5000'], 'active' => ['sometimes', 'boolean'],
        ]);
        if ($t->channel === 'email' && array_key_exists('subject', $data) && ! $data['subject']) {
            throw new DomainException('An email template needs a subject.', 'subject-required');
        }
        $t->update($data);

        return $t;
    }

    public function preview(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate([
            'template_id' => ['nullable', 'integer'], 'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:5000'], 'variables' => ['sometimes', 'array'],
        ]);
        $t = ! empty($data['template_id']) ? NotificationTemplate::findOrFail($data['template_id']) : null;
        $subject = $data['subject'] ?? $t?->subject;
        $body = $data['body'] ?? $t?->body ?? '';
        $vars = ($data['variables'] ?? []) + self::SAMPLE;

        return [
            'subject' => TemplateRenderer::render($subject, $vars), 'body' => TemplateRenderer::render($body, $vars),
            'variables' => TemplateRenderer::variables(($subject ?? '').' '.$body),
        ];
    }

    public function outbox(Request $request)
    {
        $this->can($request, 'view');

        return $this->scoped()->orderByDesc('id')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->query('event'), fn ($q, $v) => $q->where('event', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where('recipient', 'ilike', '%'.$v.'%'))
            ->paginate(min((int) $request->query('per_page', 25), 100));
    }

    public function resend(Request $request, int $id, Notifier $notifier)
    {
        $this->can($request, 'manage');
        $m = $this->scoped()->findOrFail($id);
        if ($m->status === 'skipped' || $m->channel === 'none') {
            throw new DomainException('This message was skipped (no consent) and cannot be resent.', 'not-resendable');
        }
        $notifier->resend($m);

        return $m->fresh();
    }

    public function preferences(Request $request, int $guest)
    {
        $this->can($request, 'view');
        $g = Guest::findOrFail($guest);
        $p = NotificationPreference::firstOrNew(['guest_id' => $g->id]);

        return [
            'guest_id' => $g->id, 'email' => $p->email ?? true, 'sms' => $p->sms ?? true, 'whatsapp' => $p->whatsapp ?? false,
            'marketing' => $p->marketing ?? true, 'marketing_consent' => (bool) $g->marketing_consent, 'language' => $g->language,
        ];
    }

    public function updatePreferences(Request $request, int $guest)
    {
        $this->can($request, 'manage', 'guests.manage');
        $g = Guest::findOrFail($guest);
        $data = $request->validate(collect(['email', 'sms', 'whatsapp', 'marketing'])->mapWithKeys(fn ($k) => [$k => ['sometimes', 'boolean']])->all());
        NotificationPreference::updateOrCreate(['guest_id' => $g->id], $data);

        return $this->preferences($request, $guest);
    }

    private function scoped()
    {
        $hotelId = $this->ctx->hotelId();

        return OutboxMessage::where('group_id', $this->ctx->groupId())
            ->when($hotelId, fn ($q) => $q->where(fn ($w) => $w->where('hotel_id', $hotelId)->orWhereNull('hotel_id')))
            ->when(! $hotelId && $this->ctx->allowedHotelIds() !== null, fn ($q) => $q->whereIn('hotel_id', $this->ctx->allowedHotelIds()));
    }

    private function can(Request $request, string $ability, ?string $alt = null): void
    {
        $u = $request->user();
        abort_unless($u->can('notifications.manage') || ($ability === 'view' && $u->can('notifications.view')) || ($alt && $u->can($alt)), 403);
    }
}

<?php

namespace App\Modules\Notifications\Http;

use App\Models\User;
use App\Modules\Notifications\Application\WhatsApp\GuestJourney;
use App\Modules\Notifications\Application\WhatsApp\TemplateRegistry;
use App\Modules\Notifications\Application\WhatsApp\WhatsAppClient;
use App\Modules\Notifications\Application\WhatsApp\WhatsAppMessenger;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppAccount;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppConversation;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppQuickReply;
use App\Modules\Notifications\Domain\WhatsApp\WhatsAppTemplate;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Two-way WhatsApp staff inbox, quick replies, template registry and number mapping. */
class MessagingController
{
    public function __construct(private TenantContext $ctx, private WhatsAppMessenger $wa) {}

    public function conversations(Request $request)
    {
        $this->ctx->requireHotelId();
        $q = WhatsAppConversation::query()
            ->when($request->query('status', 'open'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->when($request->query('assigned') === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->query('assigned') === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$v%")->orWhere('wa_id', 'like', '%'.preg_replace('/\D/', '', $v).'%')))
            ->orderByDesc('last_message_at')->orderByDesc('id');

        return $q->paginate(30)->through(fn (WhatsAppConversation $c) => $this->summary($c));
    }

    public function show(int $id)
    {
        $c = WhatsAppConversation::findOrFail($id);
        $c->update(['unread' => 0]);
        $r = $c->reservation_id ? Reservation::with('room')->find($c->reservation_id) : null;

        return $this->summary($c) + [
            'reservation' => $r ? ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'arrival' => $r->arrival?->toDateString(),
                'departure' => $r->departure?->toDateString(), 'room' => $r->room?->number] : null,
            'messages' => $c->messages()->limit(500)->get(['id', 'direction', 'type', 'body', 'template_name', 'media', 'journey', 'status', 'error', 'sent_by', 'dry_run', 'created_at', 'delivered_at', 'read_at']),
        ];
    }

    public function reply(Request $request, int $id)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4096']]);

        return response()->json($this->wa->reply(WhatsAppConversation::findOrFail($id), $data['text'], $request->user()), 201);
    }

    public function media(Request $request, int $id)
    {
        $data = $request->validate(['type' => ['required', 'in:image,document,audio,video'], 'link' => ['required', 'url', 'max:2000'], 'caption' => ['nullable', 'string', 'max:1024']]);

        return response()->json($this->wa->sendMedia(WhatsAppConversation::findOrFail($id), $data['type'], $data['link'], $data['caption'] ?? null, $request->user()), 201);
    }

    public function template(Request $request, int $id, GuestJourney $journey)
    {
        $data = $request->validate(['journey' => ['required', Rule::in(array_keys(TemplateRegistry::DEFAULTS))]]);
        $c = WhatsAppConversation::findOrFail($id);
        $r = $c->reservation_id ? Reservation::with('room')->find($c->reservation_id) : null;
        $vars = $r ? $journey->varsFor($data['journey'], $r) : ['guest_name' => $c->name];
        if ($vars === null) {
            throw new DomainException('Nothing to send for this step.', 'not-applicable');
        }

        return response()->json($this->wa->sendTemplate($c, $data['journey'], $vars, $request->user()), 201);
    }

    public function assign(Request $request, int $id)
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);
        $c = WhatsAppConversation::findOrFail($id);
        if ($data['user_id'] ?? null) {
            abort_unless(User::where('group_id', $c->group_id)->whereKey($data['user_id'])->exists(), 422, 'Unknown user.');
        }
        $c->update(['assigned_to' => $data['user_id'] ?? null]);

        return $this->summary($c);
    }

    public function status(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'in:open,closed']]);
        $c = WhatsAppConversation::findOrFail($id);
        $c->update(['status' => $data['status']]);

        return $this->summary($c);
    }

    public function staff()
    {
        return User::where('group_id', $this->ctx->groupId())->orderBy('name')->get(['id', 'name']);
    }

    public function quickReplies()
    {
        $this->ctx->requireHotelId();

        return WhatsAppQuickReply::orderBy('title')->get();
    }

    public function storeQuickReply(Request $request)
    {
        $this->ctx->requireHotelId();

        return response()->json(WhatsAppQuickReply::create($this->quickReplyData($request)), 201);
    }

    public function updateQuickReply(Request $request, int $id)
    {
        $q = WhatsAppQuickReply::findOrFail($id);
        $q->update($this->quickReplyData($request));

        return $q;
    }

    public function destroyQuickReply(int $id)
    {
        WhatsAppQuickReply::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function templates()
    {
        $this->ctx->requireHotelId();
        TemplateRegistry::ensureForCurrentHotel();

        return ['dry_run' => app(WhatsAppClient::class)->dryRun(), 'journey_enabled' => GuestJourney::enabled(),
            'templates' => WhatsAppTemplate::orderBy('journey')->orderBy('locale')->get()];
    }

    public function updateTemplate(Request $request, int $id)
    {
        $t = WhatsAppTemplate::findOrFail($id);
        $data = $request->validate([
            'body' => ['sometimes', 'string', 'max:1024'], 'meta_status' => ['sometimes', 'in:draft,submitted,approved,rejected,paused'],
            'meta_template_id' => ['nullable', 'string', 'max:64'], 'rejected_reason' => ['nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'in:utility,marketing'],
        ]);
        if (isset($data['body']) && $data['body'] !== $t->body && ! isset($data['meta_status'])) {
            $data['meta_status'] = 'draft'; // an edited body needs a fresh Meta approval
        }
        $t->update($data);

        return $t;
    }

    public function accounts()
    {
        $this->ctx->requireHotelId();

        return WhatsAppAccount::get();
    }

    public function storeAccount(Request $request)
    {
        $this->ctx->requireHotelId();
        $data = $request->validate(['phone_number_id' => ['required', 'string', 'max:64', 'unique:whatsapp_accounts,phone_number_id'], 'display_phone' => ['nullable', 'string', 'max:32']]);

        return response()->json(WhatsAppAccount::create($data), 201);
    }

    private function quickReplyData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:2000'], 'locale' => ['nullable', 'in:fr,en']])
            + ['locale' => 'fr'];
    }

    private function summary(WhatsAppConversation $c): array
    {
        $last = $c->messages()->reorder()->latest('id')->first(['body', 'type', 'direction']);

        return [
            'id' => $c->id, 'channel' => $c->channel ?? 'whatsapp', 'wa_id' => $c->wa_id, 'name' => $c->name, 'guest_id' => $c->guest_id, 'reservation_id' => $c->reservation_id,
            'locale' => $c->locale, 'status' => $c->status, 'assigned_to' => $c->assigned_to, 'unread' => $c->unread,
            'last_message_at' => $c->last_message_at, 'last_inbound_at' => $c->last_inbound_at, 'window_open' => $c->windowOpen(),
            'window_closes_at' => $c->last_inbound_at?->copy()->addHours(24), 'opted_out' => $this->wa->optedOut($c->wa_id, $c->group_id),
            'last' => $last ? ['body' => $last->body, 'type' => $last->type, 'direction' => $last->direction] : null,
        ];
    }
}

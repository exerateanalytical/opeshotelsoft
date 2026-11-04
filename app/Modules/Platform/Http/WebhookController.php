<?php

namespace App\Modules\Platform\Http;

use App\Modules\Platform\Application\DeliverWebhook;
use App\Modules\Platform\Domain\WebhookSubscription;
use App\Platform\Events\StoredEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('integrations.manage'), 403);

        return WebhookSubscription::orderBy('id')->get();
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $data = $request->validate([
            'url' => ['required', 'url:https,http'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', 'max:100'],
            'hotel_id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::exists('hotels', 'id')->where('group_id', $request->user()->group_id)],
        ]);
        // A hotel-scoped user may only subscribe to events of a hotel they are allowed in, never to the whole group.
        $allowed = $request->user()->allowedHotelIds();
        if ($allowed !== null) {
            abort_if(empty($data['hotel_id']) || ! in_array((int) $data['hotel_id'], array_map('intval', $allowed), true), 403,
                'You can only subscribe to events of a hotel you are allowed in.');
        }
        $secret = 'whsec_'.Str::random(40);
        $sub = WebhookSubscription::create($data + ['secret' => $secret]);

        // The secret is shown once, at creation.
        return response()->json($sub->toArray() + ['secret' => $secret], 201);
    }

    public function destroy(Request $request, WebhookSubscription $webhook)
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $webhook->update(['active' => false]);

        return response()->noContent();
    }

    public function deliveries(Request $request, WebhookSubscription $webhook)
    {
        abort_unless($request->user()->can('integrations.manage'), 403);

        return DB::table('webhook_deliveries')->where('webhook_subscription_id', $webhook->id)
            ->orderByDesc('id')->paginate(50);
    }

    public function replay(Request $request, WebhookSubscription $webhook, int $delivery)
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $row = DB::table('webhook_deliveries')->where('webhook_subscription_id', $webhook->id)->where('id', $delivery)->first();
        abort_unless($row, 404);
        DB::table('webhook_deliveries')->where('id', $row->id)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()]);
        DeliverWebhook::dispatch($row->id);

        return response()->json(['status' => 'queued']);
    }

    /** Event feed: pull-based alternative to webhooks (cursor = last event id). */
    public function events(Request $request)
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $q = StoredEvent::where('group_id', $request->user()->group_id)
            ->where('id', '>', (int) $request->query('after', 0))
            ->orderBy('id')->limit(min((int) $request->query('limit', 100), 500));
        if ($type = $request->query('type')) {
            $q->where('type', 'like', str_replace('*', '%', $type));
        }
        $events = $q->get();

        return response()->json([
            'data' => $events->map->envelope(),
            'next_cursor' => $events->last()?->id,
        ]);
    }
}

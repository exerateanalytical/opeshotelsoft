<?php

namespace App\Modules\Channels\Http;

use App\Modules\Channels\Application\AriBuilder;
use App\Modules\Channels\Application\ChannelGateway;
use App\Modules\Channels\Application\PushAri;
use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController
{
    /** Credential keys each driver reads (see Application/Drivers). */
    private const CREDENTIAL_FIELDS = [
        'booking_com' => ['username', 'password', 'webhook_secret'],
        'expedia' => ['username', 'password', 'webhook_secret'],
        'tripadvisor' => ['webhook_secret'],
        'channex' => ['api_key', 'webhook_secret'],
        'myallocator' => ['vendor_id', 'vendor_password', 'user_token', 'webhook_secret'],
        'cloudbeds' => ['access_token', 'webhook_secret'],
        'siteminder' => ['username', 'password', 'webhook_secret'],
    ];

    public function __construct(private ChannelGateway $gateway, private TenantContext $ctx) {}

    public function providers()
    {
        return collect(Connection::PROVIDERS)->map(fn ($p, $code) => [
            'code' => $code, 'name' => $p[0], 'kind' => $p[1], 'protocol' => $p[2],
            'credential_fields' => self::CREDENTIAL_FIELDS[$code] ?? [],
        ])->values();
    }

    public function index(Request $request)
    {
        $this->can($request);

        return Connection::with('roomMappings', 'rateMappings')->get()->map(fn ($c) => $this->present($c));
    }

    public function store(Request $request, EventBus $bus)
    {
        $this->can($request);
        $data = $this->validated($request, true);
        $c = Connection::create($data);
        $bus->record('channel.connection_created', ['id' => $c->id, 'provider' => $c->provider], 'channel_connection', $c->id);

        return response()->json($this->present($c->fresh('roomMappings', 'rateMappings')), 201);
    }

    public function update(Request $request, Connection $connection)
    {
        $this->can($request);
        $data = $this->validated($request, false);
        if (isset($data['credentials'])) {
            // Merge so a partial update does not wipe other secrets.
            $data['credentials'] = array_merge($connection->credentials ?? [], array_filter($data['credentials'], fn ($v) => $v !== null && $v !== ''));
        }
        $connection->update($data);

        return $this->present($connection->fresh('roomMappings', 'rateMappings'));
    }

    /** PUT mappings: replace room and rate mappings. */
    public function mappings(Request $request, Connection $connection)
    {
        $this->can($request);
        $hotelId = $this->ctx->requireHotelId();
        $data = $request->validate([
            'rooms' => ['required', 'array'], 'rooms.*.room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'rooms.*.external_room_id' => ['required', 'string', 'max:64'], 'rooms.*.max_allotment' => ['nullable', 'integer', 'min:0'],
            'rates' => ['sometimes', 'array'], 'rates.*.rate_plan_id' => ['required', 'integer', Rule::exists('rate_plans', 'id')->where('hotel_id', $hotelId)],
            'rates.*.external_rate_id' => ['required', 'string', 'max:64'], 'rates.*.markup_percent' => ['sometimes', 'numeric', 'between:-50,100'],
        ]);
        $connection->roomMappings()->delete();
        $connection->roomMappings()->createMany($data['rooms']);
        if (isset($data['rates'])) {
            $connection->rateMappings()->delete();
            $connection->rateMappings()->createMany($data['rates']);
        }

        return $connection->fresh('roomMappings', 'rateMappings');
    }

    /** Preview the exact payloads that would be sent (no network call). */
    public function preview(Request $request, Connection $connection, AriBuilder $builder)
    {
        $this->can($request);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $rows = $builder->rows($connection, $data['from'], $data['to']);

        $requests = array_map(fn ($r) => ['headers' => $this->maskHeaders($r['headers'] ?? [])] + $r, $this->gateway->driver($connection)->ariRequests($connection, $rows));

        return ['rows' => $rows, 'requests' => $requests, 'dry_run' => $this->gateway->isDryRun($connection)];
    }

    /** Full re-sync for the connection horizon. */
    public function sync(Request $request, Connection $connection)
    {
        $this->can($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        PushAri::dispatch($connection->id, $data['from'] ?? now()->toDateString(), $data['to'] ?? now()->addDays($connection->sync_days)->toDateString());

        return response()->json(['status' => 'queued'], 202);
    }

    public function test(Request $request, Connection $connection)
    {
        $this->can($request);
        $req = $this->gateway->driver($connection)->testRequest($connection);
        if (! $req) {
            return ['status' => 'not_applicable'];
        }
        $log = $this->gateway->send($connection, 'test', [$req])[0];

        return ['status' => $log->status, 'http_status' => $log->http_status, 'error' => $log->error];
    }

    public function logs(Request $request, Connection $connection)
    {
        $this->can($request);

        return SyncLog::where('channel_connection_id', $connection->id)->orderByDesc('id')->paginate(50);
    }

    /** Connection as JSON: secrets never leave the server, only which keys are set (masked). */
    private function present(Connection $c): array
    {
        return $c->toArray() + [
            'credential_keys' => collect($c->credentials ?? [])->filter(fn ($v) => $v !== null && $v !== '')->map(fn () => '********')->all(),
            'has_credentials' => $c->hasCredentials(),
            'dry_run' => $this->gateway->isDryRun($c),
            'inbound_url' => url("/api/v1/channels/inbound/{$c->uuid}"),
        ];
    }

    private function maskHeaders(array $headers): array
    {
        foreach ($headers as $k => $v) {
            if (preg_match('/auth|key|token|secret|password/i', (string) $k)) {
                $headers[$k] = '********';
            }
        }

        return $headers;
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'provider' => [$req, Rule::in(array_keys(Connection::PROVIDERS)), Rule::unique('channel_connections')->where('hotel_id', $this->ctx->requireHotelId())],
            'name' => [$req, 'string', 'max:100'],
            'property_code' => ['nullable', 'string', 'max:64'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
            'environment' => ['sometimes', Rule::in(['sandbox', 'production'])],
            'status' => ['sometimes', Rule::in(['draft', 'testing', 'active', 'suspended'])],
            'push_rates' => ['sometimes', 'boolean'], 'push_availability' => ['sometimes', 'boolean'],
            'receive_reservations' => ['sometimes', 'boolean'], 'sync_days' => ['sometimes', 'integer', 'between:30,730'],
            'settings' => ['sometimes', 'array'],
        ]);
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('channels.manage'), 403);
    }
}

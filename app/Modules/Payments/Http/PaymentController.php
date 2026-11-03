<?php

namespace App\Modules\Payments\Http;

use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\PaymentGatewaySetting;
use App\Modules\Payments\Domain\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff API: payment intents, refunds and per-hotel gateway settings. */
class PaymentController
{
    public function __construct(private PaymentService $payments) {}

    public function index(Request $request)
    {
        $this->can($request, 'payments.view');

        return PaymentIntent::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('provider'), fn ($q, $v) => $q->where('provider', $v))
            ->when($request->query('folio_id'), fn ($q, $v) => $q->where('folio_id', $v))
            ->when($request->query('reservation_id'), fn ($q, $v) => $q->where('reservation_id', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('reference', 'ilike', "%$v%")
                ->orWhere('provider_reference', 'ilike', "%$v%")->orWhere('payer_phone', 'ilike', "%$v%")->orWhere('payer_email', 'ilike', "%$v%")))
            ->with(['folio:id,number,name', 'reservation:id,number'])
            ->orderByDesc('id')->paginate(50);
    }

    public function store(Request $request)
    {
        $this->can($request, 'payments.manage');
        $data = $request->validate([
            'provider' => ['required', Rule::in(PaymentGatewaySetting::PROVIDERS)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'folio_id' => ['nullable', 'integer', 'required_without:reservation_id'],
            'reservation_id' => ['nullable', 'integer', 'required_without:folio_id'],
            'payer_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9]{8,15}$/'],
            'payer_email' => ['nullable', 'email', 'max:150'],
            'return_url' => ['nullable', 'url', 'max:500'],
        ]);

        return response()->json($this->present($this->payments->create($data, 'staff', $request->user())), 201);
    }

    /** Detail and status polling: a pending intent is re-checked with the gateway. */
    public function show(Request $request, int $intent)
    {
        $this->can($request, 'payments.view');
        $i = $this->payments->refresh(PaymentIntent::findOrFail($intent));

        return $this->present($i->fresh()->load(['refunds', 'callbacks', 'folio:id,number,name,balance,status', 'reservation:id,number']));
    }

    public function refund(Request $request, int $intent)
    {
        $this->can($request, 'payments.refund');
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:255']]);
        $refund = $this->payments->refund(PaymentIntent::findOrFail($intent), $data['amount'], $data['reason'], $request->user());

        return response()->json($refund, 201);
    }

    public function gateways(Request $request)
    {
        $this->can($request, 'payments.settings');
        $rows = PaymentGatewaySetting::all()->keyBy('provider');

        return collect(PaymentGatewaySetting::PROVIDERS)->map(function ($p) use ($rows) {
            $g = $rows[$p] ?? null;

            return [
                'provider' => $p, 'enabled' => $g?->enabled ?? true, 'mode' => $g?->mode ?? 'sandbox',
                'configured' => (bool) $g?->hasCredentials(), 'driver' => $g?->hasCredentials() ? $p : 'dry_run',
                'fields' => collect(PaymentGatewaySetting::FIELDS[$p])->map(fn ($secret, $f) => ['name' => $f, 'secret' => $secret])->values(),
                'credentials' => $g ? $g->masked() : array_fill_keys(array_keys(PaymentGatewaySetting::FIELDS[$p]), null),
                'callback_url' => url("/api/v1/payments/callbacks/$p/{reference}"),
            ];
        })->values();
    }

    /** Save settings. Blank credential fields keep the stored value; send null to clear one. */
    public function saveGateway(Request $request, string $provider)
    {
        $this->can($request, 'payments.settings');
        abort_unless(in_array($provider, PaymentGatewaySetting::PROVIDERS, true), 404);
        $fields = array_keys(PaymentGatewaySetting::FIELDS[$provider]);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'mode' => ['sometimes', Rule::in(['sandbox', 'live'])],
            'credentials' => ['sometimes', 'array'], 'credentials.*' => ['nullable', 'string', 'max:500'],
            'credentials.base_url' => ['nullable', 'url', 'max:255'],
        ]);
        $g = PaymentGatewaySetting::firstOrNew(['provider' => $provider]);
        $creds = $g->credentials ?? [];
        foreach (array_intersect_key($data['credentials'] ?? [], array_flip($fields)) as $k => $v) {
            if ($v === null) {
                unset($creds[$k]);
            } elseif ($v !== '' && ! str_starts_with($v, '••')) {
                $creds[$k] = $v;
            }
        }
        $g->fill(array_intersect_key($data, array_flip(['enabled', 'mode'])));
        $g->credentials = $creds;
        $g->save();

        return ['provider' => $provider, 'enabled' => $g->enabled ?? true, 'mode' => $g->mode ?? 'sandbox', 'configured' => $g->hasCredentials(), 'credentials' => $g->masked()];
    }

    private function present(PaymentIntent $i): array
    {
        return $i->toArray() + ['refundable' => $i->status === 'succeeded' ? $i->amount - $i->refunded_amount : 0];
    }

    private function can(Request $request, string $perm): void
    {
        abort_unless($request->user()?->can($perm), 403);
    }
}

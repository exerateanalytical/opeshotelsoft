<?php

namespace App\Modules\Payments\Http;

use App\Modules\Payments\Application\Drivers\DryRunGateway;
use App\Modules\Payments\Application\InboundMatcher;
use App\Modules\Payments\Application\PayLinkService;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\PaymentGatewaySetting;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\Payments\Domain\PaymentLink;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public (no login) Q5 endpoints: the guest pay page API, authenticated by the
 * link's short signature, and the inbound MoMo notification relay (HMAC-signed).
 */
class PublicPayLinkController
{
    public function __construct(private PayLinkService $links, private PaymentService $payments, private InboundMatcher $matcher, private TenantContext $ctx) {}

    public function show(string $code, string $sig)
    {
        $link = $this->links->findPublic($code, $sig);

        return $this->links->asHotel($link->hotel_id, function () use ($link) {
            $hotel = Hotel::findOrFail($link->hotel_id);
            $enabled = collect(PaymentGatewaySetting::PROVIDERS)->reject(fn ($p) => PaymentGatewaySetting::where('provider', $p)->where('enabled', false)->exists())->values();

            return $this->presentPublic($link) + ['hotel' => ['name' => $hotel->name], 'providers' => $enabled];
        });
    }

    public function pay(Request $request, string $code, string $sig)
    {
        $link = $this->links->findPublic($code, $sig);
        $d = $request->validate([
            'provider' => ['required', Rule::in(PaymentGatewaySetting::PROVIDERS)], 'amount' => ['nullable', 'integer', 'min:1'],
            'payer_phone' => ['nullable', 'required_unless:provider,card', 'string', 'max:30', 'regex:/^\+?[0-9]{8,15}$/'],
            'payer_email' => ['nullable', 'email', 'max:150'],
        ]);
        $intent = $this->links->asHotel($link->hotel_id, fn () => $this->links->startPayment(PaymentLink::lockForUpdate()->findOrFail($link->id), $d));

        return response()->json($this->presentIntent($intent), 201);
    }

    public function status(string $code, string $sig, string $reference)
    {
        $link = $this->links->findPublic($code, $sig);
        $intent = $this->links->asHotel($link->hotel_id, function () use ($link, $reference) {
            $i = PaymentIntent::where('reference', $reference)->where('payment_link_id', $link->id)->firstOrFail();

            return $this->payments->refresh($i)->fresh();
        });
        $fresh = $this->ctx->withoutScope(fn () => PaymentLink::findOrFail($link->id));

        return $this->presentIntent($intent) + ['link' => $this->presentPublic($fresh)];
    }

    /**
     * Inbound MoMo notification (money received on the merchant wallet without a started intent).
     * Signed X-Signature = HMAC-SHA256(body, hotel callback_secret) — the app key while no credentials (dry run).
     */
    public function inbound(Request $request, string $provider, int $hotel)
    {
        abort_unless(in_array($provider, ['mtn_momo', 'orange_money'], true), 404);
        $h = $this->ctx->withoutScope(fn () => Hotel::find($hotel)) ?? abort(404);

        return $this->links->asHotel($h->id, function () use ($request, $provider) {
            $setting = PaymentGatewaySetting::where('provider', $provider)->first();
            $secret = $setting?->credentials['callback_secret'] ?? (app()->isProduction() ? null : DryRunGateway::secret());
            $given = (string) $request->header('X-Signature', '');
            abort_unless($secret && $given !== '' && hash_equals(DryRunGateway::sign($request->getContent(), $secret), $given), 401);
            $d = $request->validate([
                'transaction_id' => ['required', 'string', 'max:100'], 'amount' => ['required', 'integer', 'min:1'],
                'payer_phone' => ['nullable', 'string', 'max:30'], 'message' => ['nullable', 'string', 'max:250'], 'date' => ['nullable', 'date'],
            ]);
            $in = $this->matcher->receiveCallback($provider, $d);

            return response()->json(['outcome' => $in->status, 'confidence' => $in->confidence]);
        });
    }

    private function presentPublic(PaymentLink $l): array
    {
        return $l->only(['code', 'label', 'amount', 'amount_paid', 'currency', 'allow_partial', 'status', 'expires_at'])
            + ['balance' => $l->balance(), 'payable' => $l->isPayable()];
    }

    private function presentIntent(PaymentIntent $i): array
    {
        return $i->only(['reference', 'provider', 'amount', 'currency', 'status', 'redirect_url', 'failure_reason', 'expires_at']);
    }
}

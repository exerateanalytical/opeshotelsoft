<?php

namespace App\Modules\Payments\Application;

use App\Models\User;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Payments\Application\Drivers\CardGateway;
use App\Modules\Payments\Application\Drivers\DryRunGateway;
use App\Modules\Payments\Application\Drivers\MtnMomoGateway;
use App\Modules\Payments\Application\Drivers\OrangeMoneyGateway;
use App\Modules\Payments\Domain\PaymentCallback;
use App\Modules\Payments\Domain\PaymentGatewaySetting;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\Payments\Domain\PaymentRefund;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment intents: start a payment with a gateway, settle it from a callback
 * or a status poll, post the money to the folio exactly once, and refund.
 * Folio postings go through FrontOffice's FolioService (which emits
 * folio.line_posted for Accounting). Online payments do not touch a
 * cashier drawer, so postings run without an actor.
 */
class PaymentService
{
    public const TTL_MINUTES = 30;

    public function __construct(private FolioService $folios, private TenantContext $ctx, private EventBus $bus) {}

    public function driverFor(string $provider, ?PaymentGatewaySetting $setting = null): PaymentGateway
    {
        if (! $setting?->hasCredentials()) {
            if (app()->isProduction()) {
                throw new DomainException('This payment method is not configured for the hotel.', 'gateway-not-configured');
            }

            return new DryRunGateway;
        }

        return match ($provider) {
            'mtn_momo' => new MtnMomoGateway,
            'orange_money' => new OrangeMoneyGateway,
            'card' => new CardGateway,
        };
    }

    /** Driver of an existing intent: the one it was created with. */
    public function driverOf(PaymentIntent $intent): PaymentGateway
    {
        return $intent->driver === 'dry_run' ? new DryRunGateway : $this->driverFor($intent->provider, $this->setting($intent->provider));
    }

    public function setting(string $provider): ?PaymentGatewaySetting
    {
        return PaymentGatewaySetting::where('provider', $provider)->first();
    }

    /**
     * @param  array{provider:string, amount:int, folio_id?:?int, reservation_id?:?int, payer_phone?:?string, payer_email?:?string, return_url?:?string}  $data
     */
    public function create(array $data, string $channel = 'staff', ?User $actor = null): PaymentIntent
    {
        $provider = $data['provider'];
        if (! isset(PaymentIntent::METHODS[$provider])) {
            throw new DomainException('Unknown payment method.');
        }
        if (($data['amount'] ?? 0) <= 0) {
            throw new DomainException('Payment amount must be positive.');
        }
        if ($provider === 'mtn_momo' && empty($data['payer_phone'])) {
            throw new DomainException('A payer phone number is required for Mobile Money.');
        }
        $folio = ! empty($data['folio_id']) ? Folio::findOrFail($data['folio_id']) : null;
        $reservation = ! empty($data['reservation_id']) ? Reservation::findOrFail($data['reservation_id']) : null;
        if (! $folio && ! $reservation) {
            throw new DomainException('A payment needs a folio or a reservation.');
        }
        if ($folio && $folio->status !== 'open') {
            throw new DomainException("Folio {$folio->number} is closed.", 'folio-closed');
        }
        if ($reservation && in_array($reservation->status, ['cancelled', 'no_show', 'checked_out'], true)) {
            throw new DomainException('This reservation can no longer take payments.');
        }
        $setting = $this->setting($provider);
        if ($setting && ! $setting->enabled) {
            throw new DomainException('This payment method is disabled for the hotel.', 'gateway-disabled');
        }
        $driver = $this->driverFor($provider, $setting);
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());

        $intent = PaymentIntent::create([
            'reference' => (string) Str::uuid(), 'provider' => $provider, 'driver' => $driver->name(),
            'amount' => (int) $data['amount'], 'currency' => $hotel->currency ?? 'XAF',
            'folio_id' => $folio?->id, 'reservation_id' => $reservation?->id ?? $folio?->reservation_id,
            'payer_phone' => $data['payer_phone'] ?? null, 'payer_email' => $data['payer_email'] ?? null,
            'channel' => $channel, 'created_by' => $actor?->id, 'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'meta' => array_filter(['return_url' => $data['return_url'] ?? null]),
        ]);

        try {
            $res = $driver->initiate($intent, $setting?->credentials ?? [], $this->callbackUrl($intent));
            $intent->update([
                'provider_reference' => $res['provider_reference'] ?? null, 'redirect_url' => $res['redirect_url'] ?? null,
                'meta' => array_merge($intent->meta ?? [], $res['meta'] ?? []),
            ]);
        } catch (DomainException $e) {
            $intent->update(['status' => 'failed', 'failure_reason' => $e->getMessage(), 'completed_at' => now()]);
        }
        $this->bus->record('payment.intent_created', $intent->only(['id', 'reference', 'provider', 'amount', 'folio_id', 'reservation_id']), 'payment_intent', $intent->id);

        return $intent->fresh();
    }

    public function callbackUrl(PaymentIntent $intent): string
    {
        return url("/api/v1/payments/callbacks/{$intent->provider}/{$intent->reference}");
    }

    /** Status poll: expire stale intents, otherwise ask the gateway. */
    public function refresh(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->isFinal()) {
            return $intent;
        }
        if ($intent->expires_at && $intent->expires_at->isPast()) {
            return $this->settle($intent, 'expired', 'Payment window expired.');
        }
        try {
            $status = $this->driverOf($intent)->status($intent, $this->setting($intent->provider)?->credentials ?? []);
        } catch (\Throwable) {
            return $intent;
        }

        return $status === 'pending' ? $intent : $this->settle($intent, $status);
    }

    /**
     * Handle a provider callback. Returns [http status, outcome].
     * The callback is logged with a unique (provider, event key); a replay is acknowledged and ignored.
     *
     * @return array{0:int, 1:string}
     */
    public function handleCallback(string $provider, string $reference, Request $request): array
    {
        $intent = $this->ctx->withoutScope(fn () => PaymentIntent::where('reference', $reference)->where('provider', $provider)->first());
        if (! $intent) {
            return [404, 'unknown-intent'];
        }

        return $this->asHotel($intent->hotel_id, function () use ($intent, $provider, $request) {
            $intent = PaymentIntent::findOrFail($intent->id);
            $driver = $this->driverOf($intent);
            $valid = $driver->verify($request, $intent, $this->setting($provider)?->credentials ?? []);
            $eventKey = hash('sha256', $request->getContent());

            if (! $valid) {
                PaymentCallback::create(['provider' => $provider, 'event_key' => 'invalid:'.Str::uuid(), 'payment_intent_id' => $intent->id,
                    'signature_valid' => false, 'payload' => $request->all(), 'outcome' => 'rejected']);

                return [401, 'invalid-signature'];
            }
            if (PaymentCallback::where('provider', $provider)->where('event_key', $eventKey)->exists()) {
                return [200, 'duplicate'];
            }
            try {
                $log = DB::transaction(fn () => PaymentCallback::create(['provider' => $provider, 'event_key' => $eventKey, 'payment_intent_id' => $intent->id,
                    'signature_valid' => true, 'payload' => $request->all()]));
            } catch (UniqueConstraintViolationException) {
                return [200, 'duplicate'];
            }
            $parsed = $driver->parse($request);
            if ($intent->isFinal()) {
                $log->update(['outcome' => 'already-'.$intent->status]);

                return [200, 'already-'.$intent->status];
            }
            if (! empty($parsed['provider_reference'])) {
                $intent->update(['meta' => array_merge($intent->meta ?? [], ['provider_transaction_id' => $parsed['provider_reference']])]);
            }
            if ($parsed['status'] === 'pending') {
                $log->update(['outcome' => 'pending']);

                return [200, 'pending'];
            }
            $intent = $this->settle($intent, $parsed['status'], $parsed['reason'] ?? null);
            $log->update(['outcome' => $intent->status]);

            return [200, $intent->status];
        });
    }

    /** Move a pending intent to a final status, posting to the folio exactly once on success. */
    public function settle(PaymentIntent $intent, string $status, ?string $reason = null): PaymentIntent
    {
        return $this->asHotel($intent->hotel_id, fn () => DB::transaction(function () use ($intent, $status, $reason) {
            $intent = PaymentIntent::lockForUpdate()->findOrFail($intent->id);
            if ($intent->isFinal()) {
                return $intent;
            }
            if ($status !== 'succeeded') {
                $intent->update(['status' => $status, 'failure_reason' => $reason, 'completed_at' => now()]);
                $this->bus->record("payment.$status", $intent->only(['id', 'reference', 'provider', 'amount']), 'payment_intent', $intent->id);

                return $intent;
            }

            $folio = $intent->folio_id ? Folio::findOrFail($intent->folio_id) : $this->folios->guestFolioFor(Reservation::findOrFail($intent->reservation_id));
            $line = $this->folios->payment($folio, $intent->methodCode(), $intent->amount, $intent->provider_reference ?? $intent->reference, [
                'description' => ($intent->reservation_id && ! $intent->folio_id ? 'Deposit - ' : 'Online payment - ').$intent->provider,
                'source_type' => 'payment_intent', 'source_id' => $intent->id,
            ]);
            $intent->update(['status' => 'succeeded', 'folio_id' => $folio->id, 'folio_line_id' => $line->id, 'completed_at' => now()]);
            if ($intent->reservation_id) {
                Reservation::whereKey($intent->reservation_id)->increment('deposit_paid', $intent->amount);
            }
            $this->bus->record('payment.succeeded', $intent->only(['id', 'reference', 'provider', 'amount', 'folio_id', 'reservation_id']), 'payment_intent', $intent->id);

            return $intent;
        }));
    }

    public function refund(PaymentIntent $intent, int $amount, string $reason, ?User $actor = null): PaymentRefund
    {
        if ($intent->status !== 'succeeded') {
            throw new DomainException('Only a successful payment can be refunded.');
        }
        if ($amount <= 0 || $amount > $intent->amount - $intent->refunded_amount) {
            throw new DomainException('The refund exceeds the amount still refundable on this payment.');
        }
        $folio = Folio::findOrFail($intent->folio_id);
        if ($folio->status !== 'open') {
            throw new DomainException("Folio {$folio->number} is closed.", 'folio-closed');
        }
        if ($folio->balance > -$amount) {
            throw new DomainException('A refund cannot exceed the credit balance on the folio.');
        }
        $res = $this->driverOf($intent)->refund($intent, $amount, $this->setting($intent->provider)?->credentials ?? []);

        return $this->asHotel($intent->hotel_id, fn () => DB::transaction(function () use ($intent, $amount, $reason, $actor, $folio, $res) {
            $intent = PaymentIntent::lockForUpdate()->findOrFail($intent->id);
            $line = $this->folios->refund($folio, $intent->methodCode(), $amount, $reason);
            $refund = PaymentRefund::create([
                'payment_intent_id' => $intent->id, 'amount' => $amount, 'reason' => $reason, 'status' => $res['status'],
                'provider_reference' => $res['provider_reference'] ?? null, 'folio_line_id' => $line->id, 'created_by' => $actor?->id,
            ]);
            $intent->increment('refunded_amount', $amount);
            $this->bus->record('payment.refunded', ['intent_id' => $intent->id, 'refund_id' => $refund->id, 'amount' => $amount], 'payment_intent', $intent->id);

            return $refund;
        }));
    }

    /** Run as the intent's hotel with no actor (no cashier drawer for online money). */
    private function asHotel(int $hotelId, callable $fn): mixed
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $hotel = $this->ctx->withoutScope(fn () => Hotel::findOrFail($hotelId));
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        try {
            return $fn();
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}

<?php

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentIntent;
use Illuminate\Http\Request;

/**
 * A payment provider driver. Drivers use Laravel's Http client only.
 * Statuses are normalised to pending | succeeded | failed.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Start the payment with the provider.
     *
     * @return array{provider_reference: ?string, redirect_url?: ?string, meta?: array}
     */
    public function initiate(PaymentIntent $intent, array $credentials, string $callbackUrl): array;

    /** Ask the provider for the current status. */
    public function status(PaymentIntent $intent, array $credentials): string;

    /** Check a callback really comes from the provider. */
    public function verify(Request $request, PaymentIntent $intent, array $credentials): bool;

    /**
     * Read a verified callback.
     *
     * @return array{status: string, provider_reference?: ?string, reason?: ?string}
     */
    public function parse(Request $request): array;

    /** @return array{status: string, provider_reference: ?string} */
    public function refund(PaymentIntent $intent, int $amount, array $credentials): array;
}

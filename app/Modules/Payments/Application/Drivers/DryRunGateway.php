<?php

namespace App\Modules\Payments\Application\Drivers;

use App\Modules\Payments\Application\PaymentGateway;
use App\Modules\Payments\Domain\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Used when a hotel has no credentials for a provider (development, offline).
 * A status check auto-succeeds, except payer phones ending in 000 or emails
 * starting with "fail", which fail, so both paths can be exercised.
 * Callbacks are signed with HMAC-SHA256 of the body using the app key (X-Signature).
 */
class DryRunGateway implements PaymentGateway
{
    use HmacSignature;

    public function name(): string
    {
        return 'dry_run';
    }

    public function initiate(PaymentIntent $intent, array $credentials, string $callbackUrl): array
    {
        Log::info('[payments:dry-run] initiate', ['reference' => $intent->reference, 'amount' => $intent->amount, 'provider' => $intent->provider]);

        return ['provider_reference' => 'DRY-'.Str::upper(Str::random(12)), 'redirect_url' => null];
    }

    public function status(PaymentIntent $intent, array $credentials): string
    {
        $fails = str_ends_with((string) $intent->payer_phone, '000') || str_starts_with((string) $intent->payer_email, 'fail');

        return $fails ? 'failed' : 'succeeded';
    }

    public function verify(Request $request, PaymentIntent $intent, array $credentials): bool
    {
        return $this->hmacValid($request, 'X-Signature', self::secret());
    }

    public function parse(Request $request): array
    {
        $s = $request->input('status');

        return ['status' => in_array($s, ['succeeded', 'failed'], true) ? $s : 'pending', 'reason' => $request->input('reason')];
    }

    public function refund(PaymentIntent $intent, int $amount, array $credentials): array
    {
        Log::info('[payments:dry-run] refund', ['reference' => $intent->reference, 'amount' => $amount]);

        return ['status' => 'succeeded', 'provider_reference' => 'DRYR-'.Str::upper(Str::random(10))];
    }

    public static function secret(): string
    {
        return (string) config('app.key');
    }
}

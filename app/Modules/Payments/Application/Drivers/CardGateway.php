<?php

namespace App\Modules\Payments\Application\Drivers;

use App\Modules\Payments\Application\PaymentGateway;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Generic hosted card checkout (CinetPay-style): create a payment, redirect
 * the guest to payment_url, receive a notification signed with x-token
 * (HMAC-SHA256 of the body with the secret key), check the transaction.
 */
class CardGateway implements PaymentGateway
{
    use HmacSignature;

    public function name(): string
    {
        return 'card';
    }

    private function base(array $c): string
    {
        return rtrim($c['base_url'] ?? 'https://api-checkout.cinetpay.com', '/');
    }

    private function auth(array $c): array
    {
        return ['apikey' => $c['api_key'] ?? '', 'site_id' => $c['site_id'] ?? ''];
    }

    public function initiate(PaymentIntent $intent, array $c, string $callbackUrl): array
    {
        $res = Http::post($this->base($c).'/v2/payment', $this->auth($c) + [
            'transaction_id' => $intent->reference, 'amount' => $intent->amount, 'currency' => $intent->currency,
            'description' => 'Hotel payment '.$intent->reference, 'notify_url' => $callbackUrl,
            'return_url' => $intent->meta['return_url'] ?? url('/'), 'channels' => 'CREDIT_CARD', 'customer_email' => $intent->payer_email,
        ]);
        if (! $res->successful() || ! $res->json('data.payment_url')) {
            throw new DomainException('The card gateway refused the payment request.', 'gateway-error', 502);
        }

        return ['provider_reference' => $res->json('data.payment_token'), 'redirect_url' => $res->json('data.payment_url')];
    }

    public function status(PaymentIntent $intent, array $c): string
    {
        return self::map(Http::post($this->base($c).'/v2/payment/check', $this->auth($c) + ['transaction_id' => $intent->reference])->json('data.status'));
    }

    public function verify(Request $request, PaymentIntent $intent, array $c): bool
    {
        return $this->hmacValid($request, 'x-token', $c['secret_key'] ?? null);
    }

    public function parse(Request $request): array
    {
        return ['status' => self::map($request->input('status')), 'provider_reference' => $request->input('operator_id'), 'reason' => $request->input('message')];
    }

    public function refund(PaymentIntent $intent, int $amount, array $c): array
    {
        $res = Http::post($this->base($c).'/v2/refund', $this->auth($c) + ['transaction_id' => $intent->reference, 'amount' => $amount]);
        if (! $res->successful()) {
            throw new DomainException('The card gateway refused the refund.', 'gateway-error', 502);
        }

        return ['status' => 'succeeded', 'provider_reference' => $res->json('data.refund_id')];
    }

    private static function map(?string $s): string
    {
        return match (strtoupper((string) $s)) {
            'ACCEPTED', 'SUCCESS' => 'succeeded', 'REFUSED', 'FAILED', 'CANCELED' => 'failed', default => 'pending',
        };
    }
}

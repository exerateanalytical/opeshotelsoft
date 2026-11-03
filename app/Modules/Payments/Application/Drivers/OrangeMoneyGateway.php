<?php

namespace App\Modules\Payments\Application\Drivers;

use App\Modules\Payments\Application\PaymentGateway;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Orange Money Web Payment. The guest is redirected to payment_url; Orange
 * posts a notification carrying the notif_token returned at initiation, which
 * proves the callback's origin. Orange Web Payment has no refund API.
 */
class OrangeMoneyGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'orange_money';
    }

    private function base(array $c): string
    {
        return rtrim($c['base_url'] ?? 'https://api.orange.com/orange-money-webpay/cm/v1', '/');
    }

    private function token(array $c): string
    {
        $res = Http::asForm()->withBasicAuth($c['client_id'] ?? '', $c['client_secret'] ?? '')
            ->post('https://api.orange.com/oauth/v3/token', ['grant_type' => 'client_credentials']);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new DomainException('Orange Money authentication failed.', 'gateway-error', 502);
        }

        return $res->json('access_token');
    }

    public function initiate(PaymentIntent $intent, array $c, string $callbackUrl): array
    {
        $return = $intent->meta['return_url'] ?? url('/');
        $res = Http::withToken($this->token($c))->post($this->base($c).'/webpayment', [
            'merchant_key' => $c['merchant_key'] ?? '', 'currency' => $intent->currency, 'order_id' => $intent->reference,
            'amount' => $intent->amount, 'return_url' => $return, 'cancel_url' => $return,
            'notif_url' => $callbackUrl, 'lang' => 'fr', 'reference' => 'Hotel',
        ]);
        if (! $res->successful() || ! $res->json('payment_url')) {
            throw new DomainException('Orange Money refused the payment request.', 'gateway-error', 502);
        }

        return ['provider_reference' => $res->json('pay_token'), 'redirect_url' => $res->json('payment_url'), 'meta' => ['notif_token' => $res->json('notif_token')]];
    }

    public function status(PaymentIntent $intent, array $c): string
    {
        $res = Http::withToken($this->token($c))->post($this->base($c).'/transactionstatus', [
            'order_id' => $intent->reference, 'amount' => $intent->amount, 'pay_token' => $intent->provider_reference,
        ]);

        return self::map($res->json('status'));
    }

    public function verify(Request $request, PaymentIntent $intent, array $c): bool
    {
        $expected = (string) ($intent->meta['notif_token'] ?? '');

        return $expected !== '' && hash_equals($expected, (string) $request->input('notif_token'));
    }

    public function parse(Request $request): array
    {
        return ['status' => self::map($request->input('status')), 'provider_reference' => $request->input('txnid'), 'reason' => $request->input('status')];
    }

    public function refund(PaymentIntent $intent, int $amount, array $c): array
    {
        throw new DomainException('Orange Money web payments cannot be refunded through the gateway. Refund by cash or transfer.', 'refund-unsupported');
    }

    private static function map(?string $s): string
    {
        return match (strtoupper((string) $s)) {
            'SUCCESS' => 'succeeded', 'FAILED', 'EXPIRED' => 'failed', default => 'pending',
        };
    }
}

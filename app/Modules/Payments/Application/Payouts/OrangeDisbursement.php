<?php

namespace App\Modules\Payments\Application\Payouts;

use App\Modules\Payments\Domain\PayoutItem;
use Illuminate\Support\Facades\Http;

/**
 * Orange Money cash-in (merchant to wallet). Orange's disbursement API is granted per
 * merchant contract; the endpoint paths below follow the Orange Money API (cashin / status)
 * and must be confirmed against the hotel's contract before going live.
 */
class OrangeDisbursement implements DisbursementGateway
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
        return (string) Http::asForm()->withBasicAuth($c['client_id'] ?? '', $c['client_secret'] ?? '')
            ->post('https://api.orange.com/oauth/v3/token', ['grant_type' => 'client_credentials'])->json('access_token');
    }

    public function disburse(PayoutItem $item, string $currency, array $c): array
    {
        try {
            $res = Http::withToken($this->token($c))->post($this->base($c).'/cashin', [
                'merchant_key' => $c['merchant_key'] ?? '', 'amount' => $item->amount, 'currency' => $currency,
                'order_id' => $item->reference, 'subscriber_msisdn' => ltrim((string) $item->destination, '+'), 'description' => 'Salaire',
            ]);
        } catch (\Throwable) {
            return ['status' => 'failed', 'provider_reference' => null, 'reason' => 'Orange unreachable'];
        }
        if (! $res->successful()) {
            return ['status' => 'failed', 'provider_reference' => null, 'reason' => 'Orange refused the transfer ('.$res->status().')'];
        }

        return ['status' => self::map($res->json('status')), 'provider_reference' => $res->json('txnid') ?? $res->json('pay_token')];
    }

    public function status(PayoutItem $item, array $c): string
    {
        return self::map(Http::withToken($this->token($c))->post($this->base($c).'/cashinstatus', [
            'order_id' => $item->reference, 'txnid' => $item->provider_reference,
        ])->json('status'));
    }

    private static function map(?string $s): string
    {
        return match (strtoupper((string) $s)) {
            'SUCCESS', 'SUCCESSFUL' => 'succeeded', 'FAILED', 'EXPIRED', 'CANCELLED' => 'failed', default => 'sent',
        };
    }
}

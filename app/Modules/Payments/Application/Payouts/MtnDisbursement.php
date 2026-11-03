<?php

namespace App\Modules\Payments\Application\Payouts;

use App\Modules\Payments\Domain\PayoutItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** MTN MoMo Disbursements API: transfer + status (same credentials as the collection gateway). */
class MtnDisbursement implements DisbursementGateway
{
    public function name(): string
    {
        return 'mtn_momo';
    }

    private function base(array $c): string
    {
        return rtrim($c['base_url'] ?? 'https://sandbox.momodeveloper.mtn.com', '/');
    }

    private function client(array $c)
    {
        $token = Http::withBasicAuth($c['api_user'] ?? '', $c['api_key'] ?? '')
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $c['subscription_key'] ?? ''])
            ->post($this->base($c).'/disbursement/token/')->json('access_token');

        return Http::withToken((string) $token)->withHeaders([
            'Ocp-Apim-Subscription-Key' => $c['subscription_key'] ?? '', 'X-Target-Environment' => $c['target_environment'] ?? 'sandbox',
        ]);
    }

    public function disburse(PayoutItem $item, string $currency, array $c): array
    {
        $ref = (string) Str::uuid();
        try {
            $res = $this->client($c)->withHeaders(['X-Reference-Id' => $ref])->post($this->base($c).'/disbursement/v1_0/transfer', [
                'amount' => (string) $item->amount, 'currency' => $currency, 'externalId' => $item->reference,
                'payee' => ['partyIdType' => 'MSISDN', 'partyId' => ltrim((string) $item->destination, '+')],
                'payerMessage' => 'Salaire', 'payeeNote' => $item->reference,
            ]);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'provider_reference' => null, 'reason' => 'MTN unreachable'];
        }

        return $res->status() === 202
            ? ['status' => 'sent', 'provider_reference' => $ref]
            : ['status' => 'failed', 'provider_reference' => null, 'reason' => 'MTN refused the transfer ('.$res->status().')'];
    }

    public function status(PayoutItem $item, array $c): string
    {
        $s = $this->client($c)->get($this->base($c).'/disbursement/v1_0/transfer/'.$item->provider_reference)->json('status');

        return match ($s) {
            'SUCCESSFUL' => 'succeeded', 'FAILED', 'REJECTED' => 'failed', default => 'sent',
        };
    }
}

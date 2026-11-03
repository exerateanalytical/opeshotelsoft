<?php

namespace App\Modules\Payments\Application\Drivers;

use App\Modules\Payments\Application\PaymentGateway;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * MTN MoMo Collections API: request-to-pay and status; refunds through the
 * Disbursements refund endpoint. MTN does not sign callbacks, so the hotel's
 * callback relay adds X-Signature (HMAC-SHA256 of the body with the hotel's
 * callback_secret); unsigned callbacks are rejected.
 */
class MtnMomoGateway implements PaymentGateway
{
    use HmacSignature;

    public function name(): string
    {
        return 'mtn_momo';
    }

    private function base(array $c): string
    {
        return rtrim($c['base_url'] ?? 'https://sandbox.momodeveloper.mtn.com', '/');
    }

    private function token(array $c, string $product): string
    {
        $res = Http::withBasicAuth($c['api_user'] ?? '', $c['api_key'] ?? '')
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $c['subscription_key'] ?? ''])
            ->post($this->base($c)."/$product/token/");
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new DomainException('MTN MoMo authentication failed.', 'gateway-error', 502);
        }

        return $res->json('access_token');
    }

    private function client(array $c, string $product = 'collection')
    {
        return Http::withToken($this->token($c, $product))->withHeaders([
            'Ocp-Apim-Subscription-Key' => $c['subscription_key'] ?? '',
            'X-Target-Environment' => $c['target_environment'] ?? 'sandbox',
        ]);
    }

    public function initiate(PaymentIntent $intent, array $c, string $callbackUrl): array
    {
        $ref = (string) Str::uuid();
        $res = $this->client($c)->withHeaders(['X-Reference-Id' => $ref, 'X-Callback-Url' => $callbackUrl])
            ->post($this->base($c).'/collection/v1_0/requesttopay', [
                'amount' => (string) $intent->amount, 'currency' => $intent->currency, 'externalId' => $intent->reference,
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => ltrim((string) $intent->payer_phone, '+')],
                'payerMessage' => 'Paiement hotel', 'payeeNote' => $intent->reference,
            ]);
        if ($res->status() !== 202) {
            throw new DomainException('MTN MoMo refused the payment request.', 'gateway-error', 502);
        }

        return ['provider_reference' => $ref, 'redirect_url' => null];
    }

    public function status(PaymentIntent $intent, array $c): string
    {
        return self::map($this->client($c)->get($this->base($c).'/collection/v1_0/requesttopay/'.$intent->provider_reference)->json('status'));
    }

    public function verify(Request $request, PaymentIntent $intent, array $c): bool
    {
        return $this->hmacValid($request, 'X-Signature', $c['callback_secret'] ?? null);
    }

    public function parse(Request $request): array
    {
        return ['status' => self::map($request->input('status')), 'provider_reference' => $request->input('financialTransactionId'), 'reason' => $request->input('reason')];
    }

    public function refund(PaymentIntent $intent, int $amount, array $c): array
    {
        $ref = (string) Str::uuid();
        $res = $this->client($c, 'disbursement')->withHeaders(['X-Reference-Id' => $ref])
            ->post($this->base($c).'/disbursement/v1_0/refund', [
                'amount' => (string) $amount, 'currency' => $intent->currency, 'externalId' => $intent->reference.'-R',
                'payerMessage' => 'Remboursement', 'payeeNote' => $intent->reference, 'referenceIdToRefund' => $intent->provider_reference,
            ]);
        if ($res->status() !== 202) {
            throw new DomainException('MTN MoMo refused the refund.', 'gateway-error', 502);
        }

        return ['status' => 'succeeded', 'provider_reference' => $ref];
    }

    private static function map(?string $s): string
    {
        return match (strtoupper((string) $s)) {
            'SUCCESSFUL' => 'succeeded', 'FAILED', 'REJECTED', 'TIMEOUT' => 'failed', default => 'pending',
        };
    }
}

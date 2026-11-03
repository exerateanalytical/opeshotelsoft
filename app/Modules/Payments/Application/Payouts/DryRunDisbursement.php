<?php

namespace App\Modules\Payments\Application\Payouts;

use App\Modules\Payments\Domain\PayoutItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** No credentials: transfers succeed at once, except wallets ending in 000 which fail (to exercise retries). */
class DryRunDisbursement implements DisbursementGateway
{
    public function name(): string
    {
        return 'dry_run';
    }

    public function disburse(PayoutItem $item, string $currency, array $credentials): array
    {
        Log::info('[payouts:dry-run] disburse', ['reference' => $item->reference, 'amount' => $item->amount, 'channel' => $item->channel]);
        if (str_ends_with((string) $item->destination, '000')) {
            return ['status' => 'failed', 'provider_reference' => null, 'reason' => 'Dry run: wallet rejected'];
        }

        return ['status' => 'succeeded', 'provider_reference' => 'DRYP-'.Str::upper(Str::random(10))];
    }

    public function status(PayoutItem $item, array $credentials): string
    {
        return str_ends_with((string) $item->destination, '000') ? 'failed' : 'succeeded';
    }
}

<?php

namespace App\Modules\Payments\Application\Payouts;

use App\Modules\Payments\Domain\PayoutItem;

/**
 * Bulk salary disbursement to a Mobile Money wallet. Statuses are normalised
 * to sent (accepted, final status later) | succeeded | failed.
 */
interface DisbursementGateway
{
    public function name(): string;

    /** @return array{status: string, provider_reference: ?string, reason?: ?string} */
    public function disburse(PayoutItem $item, string $currency, array $credentials): array;

    /** Final status of a sent transfer: sent | succeeded | failed. */
    public function status(PayoutItem $item, array $credentials): string;
}

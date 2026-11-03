<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\TaxCode;

/**
 * Tax computation shared by folios and POS (published interface of the
 * Front Office context). Percentage taxes may be included in the price
 * (net is backed out of the gross) or added on top; fixed taxes are
 * per night or per unit.
 */
class TaxCalculator
{
    /** @return array{0:int, 1:array<int, array{code:string, name:string, rate:float, amount:int, account:string}>, 2:int} tax total, breakdown, final gross */
    public static function compute(ChargeCode $code, int $gross, int $nights = 1): array
    {
        $taxCodes = TaxCode::whereIn('code', $code->tax_codes ?? [])->where('active', true)->get()->values();
        $percentIncl = $taxCodes->where('kind', 'percent')->where('included_in_price', true)->sum('rate');
        $net = $percentIncl > 0 ? (int) round($gross / (1 + $percentIncl / 100)) : $gross;

        $breakdown = [];
        $inclusiveIdx = null;
        $inclusiveSum = 0;
        $added = 0;
        foreach ($taxCodes as $t) {
            $amount = match ($t->kind) {
                'percent' => (int) round($net * $t->rate / 100),
                'fixed_per_night' => $t->fixed_amount * $nights,
                default => $t->fixed_amount,
            };
            if ($t->kind === 'percent' && $t->included_in_price) {
                $inclusiveIdx ??= count($breakdown);
                $inclusiveSum += $amount;
            } elseif (! $t->included_in_price) {
                $added += $amount;
            }
            $breakdown[] = ['code' => $t->code, 'name' => $t->name, 'rate' => $t->rate, 'amount' => $amount, 'account' => $t->account];
        }

        // Rounding: net + included percentage taxes must equal the gross exactly.
        if ($inclusiveIdx !== null) {
            $breakdown[$inclusiveIdx]['amount'] += ($gross - $net) - $inclusiveSum;
        }

        return [array_sum(array_column($breakdown, 'amount')), $breakdown, $gross + $added];
    }
}

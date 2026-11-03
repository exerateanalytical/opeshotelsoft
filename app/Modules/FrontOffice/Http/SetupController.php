<?php

namespace App\Modules\FrontOffice\Http;

use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\FrontOffice\Domain\TaxCode;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tax codes, charge codes and payment methods for a hotel. */
class SetupController
{
    private const MAP = [
        'tax-codes' => [TaxCode::class, 'accounting'],
        'charge-codes' => [ChargeCode::class, 'accounting'],
        'payment-methods' => [PaymentMethod::class, 'accounting'],
    ];

    public function index(Request $request, string $kind)
    {
        [$model] = $this->resolve($request, $kind, false);

        return $model::orderBy('code')->get();
    }

    public function upsert(Request $request, EventBus $bus, string $kind)
    {
        [$model] = $this->resolve($request, $kind, true);
        $rules = match ($kind) {
            'tax-codes' => [
                'code' => ['required', 'string', 'max:16'], 'name' => ['required', 'string'],
                'kind' => ['sometimes', Rule::in(['percent', 'fixed_per_night', 'fixed_per_unit'])],
                'rate' => ['sometimes', 'numeric', 'between:0,100'], 'fixed_amount' => ['sometimes', 'integer', 'min:0'],
                'included_in_price' => ['sometimes', 'boolean'], 'account' => ['required', 'string', 'max:20'], 'active' => ['sometimes', 'boolean'],
            ],
            'charge-codes' => [
                'code' => ['required', 'string', 'max:16'], 'name' => ['required', 'string'], 'department' => ['required', 'string', 'max:30'],
                'revenue_account' => ['required', 'string', 'max:20'], 'tax_codes' => ['sometimes', 'array'], 'active' => ['sometimes', 'boolean'],
            ],
            'payment-methods' => [
                'code' => ['required', 'string', 'max:16'], 'name' => ['required', 'string'],
                'kind' => ['required', Rule::in(['cash', 'card', 'mobile_money', 'bank_transfer', 'cheque', 'city_ledger', 'voucher', 'loyalty_points'])],
                'account' => ['required', 'string', 'max:20'], 'provider' => ['nullable', 'string'], 'active' => ['sometimes', 'boolean'],
            ],
        };
        $data = $request->validate($rules);
        $record = $model::updateOrCreate(['code' => $data['code']], $data);
        $bus->record('setup.'.str_replace('-', '_', $kind).'.saved', $record->toArray());

        return $record;
    }

    private function resolve(Request $request, string $kind, bool $manage): array
    {
        abort_unless(isset(self::MAP[$kind]), 404);
        [$model, $perm] = self::MAP[$kind];
        $u = $request->user();
        abort_unless($u->can("$perm.manage") || $u->can('hotels.manage') || (! $manage && ($u->can("$perm.view") || $u->can('folios.manage') || $u->can('pos.manage'))), 403);

        return [$model];
    }
}

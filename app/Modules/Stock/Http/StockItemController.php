<?php

namespace App\Modules\Stock\Http;

use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Stock\Domain\StockItem;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockItemController extends ResourceController
{
    protected string $model = StockItem::class;

    protected string $permission = 'stock';

    protected string $event = 'stock.item';

    protected array $searchable = ['name', 'code'];

    protected array $filterable = ['category', 'active', 'preferred_supplier_id'];

    /** Default GL accounts by category (SYSCOHADA). */
    private const ACCOUNTS = [
        'food' => ['inventory_food', 'cost_food'],
        'beverage' => ['inventory_beverage', 'cost_beverage'],
    ];

    protected function rules(Request $request, ?Model $existing): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', Rule::unique('stock_items')->where('hotel_id', $hotelId)->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:150'],
            'category' => [$req, Rule::in(['food', 'beverage', 'supplies', 'amenities', 'linen', 'cleaning', 'maintenance'])],
            'unit' => [$req, 'string', 'max:10'],
            'purchase_unit' => ['nullable', 'string', 'max:10'],
            'purchase_factor' => ['sometimes', 'numeric', 'min:0.0001'],
            'inventory_account' => ['sometimes', 'string', 'max:20'],
            'cost_account' => ['sometimes', 'string', 'max:20'],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'reorder_quantity' => ['sometimes', 'numeric', 'min:0'],
            'preferred_supplier_id' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        if (! $existing) {
            [$inv, $cost] = self::ACCOUNTS[$data['category']] ?? ['inventory_supplies', 'cost_supplies'];
            $data['inventory_account'] ??= C::role($inv);
            $data['cost_account'] ??= C::role($cost);
        }

        return $data;
    }
}

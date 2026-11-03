<?php

namespace App\Modules\POS\Http;

use App\Modules\POS\Domain\PriceList;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Price levels (happy hour...) per outlet with a weekly time window. */
class PriceListController extends ResourceController
{
    use ConfiguresPos;

    protected string $model = PriceList::class;

    protected string $permission = 'pos';

    protected string $event = 'pos.price_list';

    protected array $filterable = ['outlet_id', 'active'];

    protected array $with = ['items'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $req = $existing ? 'sometimes' : 'required';

        return [
            'outlet_id' => ['nullable', 'integer', Rule::exists('outlets', 'id')->where('hotel_id', $hotelId)],
            'name' => [$req, 'string', 'max:100'],
            'days' => ['sometimes', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'starts_at' => [$req, 'date_format:H:i'],
            'ends_at' => [$req, 'date_format:H:i', 'different:starts_at'],
            'percent_off' => ['sometimes', 'numeric', 'between:0,100'],
            'priority' => ['sometimes', 'integer', 'between:0,100'],
            'active' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array'],
            'items.*.pos_menu_item_id' => ['required', 'integer', 'distinct', Rule::exists('pos_menu_items', 'id')->where('hotel_id', $hotelId)],
            'items.*.price' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        unset($data['items']);

        return $data;
    }

    protected function afterSave(Model $record, array $data): void
    {
        if (array_key_exists('items', $data)) {
            $record->items()->delete();
            foreach ($data['items'] as $i) {
                $record->items()->create(['pos_menu_item_id' => $i['pos_menu_item_id'], 'price' => $i['price']]);
            }
        }
    }
}

<?php

namespace App\Modules\POS\Http;

use App\Modules\POS\Domain\MenuItem;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuItemController extends ResourceController
{
    protected string $model = MenuItem::class;

    protected string $permission = 'pos';

    protected string $event = 'pos.menu_item';

    protected array $searchable = ['name', 'code', 'category'];

    protected array $filterable = ['outlet_id', 'category', 'active', 'charge_code'];

    protected array $with = ['components'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', Rule::unique('pos_menu_items')->where('hotel_id', $hotelId)->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'outlet_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:50'],
            'price' => [$req, 'integer', 'min:0'],
            'charge_code' => ['sometimes', 'string', Rule::exists('charge_codes', 'code')->where('hotel_id', $hotelId)],
            'kitchen_station' => ['nullable', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
            'components' => ['sometimes', 'array'],
            'components.*.stock_item_id' => ['required', 'integer'],
            'components.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        unset($data['components']);

        return $data;
    }

    protected function afterSave(Model $record, array $data): void
    {
        if (array_key_exists('components', $data)) {
            $record->components()->delete();
            $record->components()->createMany($data['components']);
        }
    }
}

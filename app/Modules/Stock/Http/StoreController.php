<?php

namespace App\Modules\Stock\Http;

use App\Modules\Stock\Domain\Store;
use App\Platform\Http\ResourceController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends ResourceController
{
    protected string $model = Store::class;

    protected string $permission = 'stock';

    protected string $event = 'stock.store';

    protected array $filterable = ['kind', 'outlet_id', 'active'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:12'],
            'name' => [$req, 'string', 'max:100'],
            'kind' => ['sometimes', Rule::in(['main', 'kitchen', 'bar', 'housekeeping', 'maintenance', 'minibar'])],
            'department' => ['nullable', 'string', 'max:30'],
            'outlet_id' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

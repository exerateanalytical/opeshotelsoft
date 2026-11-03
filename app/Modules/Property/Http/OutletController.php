<?php

namespace App\Modules\Property\Http;

use App\Modules\Property\Domain\Outlet;
use App\Platform\Http\ResourceController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OutletController extends ResourceController
{
    protected string $model = Outlet::class;

    protected string $permission = 'property';

    protected string $event = 'property.outlet';

    protected array $filterable = ['kind', 'active'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:12'],
            'name' => [$req, 'string', 'max:100'],
            'kind' => ['sometimes', Rule::in(['restaurant', 'bar', 'room_service', 'spa', 'laundry', 'minibar', 'shop', 'events', 'other'])],
            'revenue_account' => ['nullable', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

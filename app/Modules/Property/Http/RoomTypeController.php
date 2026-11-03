<?php

namespace App\Modules\Property\Http;

use App\Modules\Property\Domain\RoomType;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomTypeController extends ResourceController
{
    protected string $model = RoomType::class;

    protected string $permission = 'property';

    protected string $event = 'property.room_type';

    protected array $searchable = ['name', 'code'];

    protected array $filterable = ['active'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:12', Rule::unique('room_types')->where('hotel_id', app(TenantContext::class)->requireHotelId())->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'max_adults' => ['sometimes', 'integer', 'between:1,12'],
            'max_children' => ['sometimes', 'integer', 'between:0,12'],
            'bed_type' => ['nullable', 'string', 'max:50'],
            'size_sqm' => ['nullable', 'integer', 'min:1'],
            'amenities' => ['sometimes', 'array'],
            'base_rate' => ['sometimes', 'integer', 'min:0'],
            'sort' => ['sometimes', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

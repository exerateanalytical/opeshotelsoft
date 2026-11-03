<?php

namespace App\Modules\Property\Http;

use App\Modules\Property\Domain\Asset;
use App\Platform\Events\EventBus;
use App\Platform\Http\ResourceController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AssetController extends ResourceController
{
    protected string $model = Asset::class;

    protected string $permission = 'maintenance';

    protected string $event = 'property.asset';

    protected array $searchable = ['name', 'tag'];

    protected array $filterable = ['category', 'room_id', 'status'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:150'],
            'tag' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'room_id' => ['nullable', 'integer'],
            'purchased_on' => ['nullable', 'date'],
            'cost' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:in_service,under_repair,retired'],
        ];
    }

    public function destroy(Request $request, EventBus $bus, string $id)
    {
        $this->authorize($request, 'manage');
        Asset::findOrFail($id)->update(['status' => 'retired']);

        return response()->noContent();
    }
}

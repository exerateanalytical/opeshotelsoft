<?php

namespace App\Modules\POS\Http;

use App\Modules\POS\Domain\PosTable;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Floor-plan tables per outlet. Viewing needs pos.view; layout changes need pos.configure. */
class PosTableController extends ResourceController
{
    use ConfiguresPos;

    protected string $model = PosTable::class;

    protected string $permission = 'pos';

    protected string $event = 'pos.table';

    protected array $filterable = ['outlet_id', 'active'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $req = $existing ? 'sometimes' : 'required';
        $outletId = $request->input('outlet_id', $existing?->outlet_id);

        return [
            'outlet_id' => [$req, 'integer', Rule::exists('outlets', 'id')->where('hotel_id', $hotelId)],
            'name' => [$req, 'string', 'max:10', Rule::unique('pos_tables')->where('outlet_id', $outletId)->ignore($existing?->id)],
            'seats' => ['sometimes', 'integer', 'between:1,50'],
            'pos_x' => ['sometimes', 'integer', 'between:0,30'],
            'pos_y' => ['sometimes', 'integer', 'between:0,30'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

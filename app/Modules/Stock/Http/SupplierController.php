<?php

namespace App\Modules\Stock\Http;

use App\Modules\Stock\Domain\Supplier;
use App\Platform\Http\ResourceController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends ResourceController
{
    protected string $model = Supplier::class;

    protected string $permission = 'purchasing';

    protected string $event = 'purchasing.supplier';

    protected array $searchable = ['name', 'code', 'tax_id'];

    protected array $filterable = ['active'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', Rule::unique('suppliers')->where('group_id', $request->user()->group_id)->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:50'], 'trade_register' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'], 'payment_method' => ['nullable', 'string'], 'bank_account' => ['nullable', 'string'],
            'momo_number' => ['nullable', 'string'], 'payment_terms_days' => ['sometimes', 'integer', 'between:0,180'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Modules\Guests\Http;

use App\Modules\Guests\Domain\Company;
use App\Platform\Http\ResourceController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends ResourceController
{
    protected string $model = Company::class;

    protected string $permission = 'guests';

    protected string $event = 'crm.company';

    protected array $searchable = ['name', 'tax_id', 'email'];

    protected array $filterable = ['kind', 'city_ledger', 'active'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'kind' => ['sometimes', Rule::in(['corporate', 'travel_agent', 'ota', 'government', 'ngo'])],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'commission_percent' => ['sometimes', 'numeric', 'between:0,50'],
            'city_ledger' => ['sometimes', 'boolean'],
            'credit_limit' => ['sometimes', 'integer', 'min:0'],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,180'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

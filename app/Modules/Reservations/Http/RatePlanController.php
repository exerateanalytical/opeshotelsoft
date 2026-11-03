<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Reservations\Domain\RatePlan;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RatePlanController extends ResourceController
{
    protected string $model = RatePlan::class;

    protected string $permission = 'rates';

    protected string $event = 'rates.rate_plan';

    protected array $searchable = ['name', 'code'];

    protected array $filterable = ['active', 'public', 'members_only', 'company_id'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $hotelId = app(TenantContext::class)->requireHotelId();
        $req = $existing ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:16', Rule::unique('rate_plans')->where('hotel_id', $hotelId)->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:100'],
            'meal_plan' => ['sometimes', Rule::in(['RO', 'BB', 'HB', 'FB', 'AI'])],
            'refundable' => ['sometimes', 'boolean'],
            'cancellation_hours' => ['sometimes', 'integer', 'between:0,720'],
            'cancellation_penalty' => ['sometimes', Rule::in(['none', 'first_night', 'percent', 'full'])],
            'penalty_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'deposit_required' => ['sometimes', 'boolean'],
            'deposit_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'members_only' => ['sometimes', 'boolean'],
            'company_id' => ['nullable', 'integer'],
            'parent_rate_plan_id' => ['nullable', 'integer', Rule::exists('rate_plans', 'id')->where('hotel_id', $hotelId)],
            'derived_adjust_percent' => ['sometimes', 'numeric', 'between:-90,200'],
            'public' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

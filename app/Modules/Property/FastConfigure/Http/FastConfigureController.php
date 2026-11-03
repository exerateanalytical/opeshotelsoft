<?php

namespace App\Modules\Property\FastConfigure\Http;

use App\Modules\Property\FastConfigure\Application\FastConfigure;
use App\Modules\Property\FastConfigure\Application\Generator;
use App\Modules\Property\FastConfigure\Application\Presets;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Fast Configure API. Needs property.fast_configure; creating a hotel also needs hotels.manage. */
class FastConfigureController
{
    public function __construct(private FastConfigure $svc) {}

    public function presets(Request $request)
    {
        $this->can($request);

        return ['presets' => Presets::catalog(), 'bed_types' => Generator::BED_TYPES, 'amenities' => Generator::AMENITIES, 'outlet_items' => Generator::OUTLET_ITEMS];
    }

    public function preset(Request $request, string $key)
    {
        $this->can($request);
        abort_unless(in_array($key, Presets::KEYS, true), 404);

        return Presets::get($key);
    }

    public function preview(Request $request)
    {
        $this->can($request);

        return $this->svc->preview($this->config($request), $request->user());
    }

    public function apply(Request $request)
    {
        $this->can($request);
        $report = $this->svc->apply($this->config($request), $request->user(), $request->boolean('dry_run'));

        return response()->json($report, $request->boolean('dry_run') ? 200 : 201);
    }

    public function export(Request $request)
    {
        $this->can($request);
        $hotel = Hotel::findOrFail((int) $request->query('hotel_id', app(TenantContext::class)->hotelId()));

        return $this->svc->export($hotel);
    }

    public function demo(Request $request)
    {
        $this->can($request);
        $data = $request->validate(['preset' => ['required', 'string'], 'name' => ['nullable', 'string', 'max:150'], 'city' => ['nullable', 'string', 'max:100']]);
        abort_unless(in_array($data['preset'], Presets::KEYS, true), 422);
        $config = Presets::get($data['preset']);
        $config['hotel'] = ['create' => array_filter(['name' => $data['name'] ?? null, 'city' => $data['city'] ?? null]) + $config['hotel']['create']];

        return response()->json($this->svc->apply($config, $request->user()), 201);
    }

    private function config(Request $request): array
    {
        $request->validate(['config' => ['required', 'array'], 'config.types' => ['sometimes', 'array', 'max:30'], 'config.explicit_rooms' => ['sometimes', 'array', 'max:'.Generator::MAX_ROOMS]]);

        return $request->input('config');
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('property.fast_configure'), 403);
    }
}

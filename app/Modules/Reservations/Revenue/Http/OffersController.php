<?php

namespace App\Modules\Reservations\Revenue\Http;

use App\Modules\Reservations\Revenue\Application\CorporateService;
use App\Modules\Reservations\Revenue\Domain\CorporateContract;
use App\Modules\Reservations\Revenue\Domain\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Promo codes and corporate contracts (negotiated rate, allotments, last-room availability). */
class OffersController extends RevenueController
{
    public function promos(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');

        return ['data' => PromoCode::orderByDesc('id')->get()];
    }

    public function savePromo(Request $request, ?PromoCode $promo = null)
    {
        $this->need($request, 'revenue.manage');
        $hotelId = app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId();
        $data = $request->validate([
            'code' => [$promo ? 'prohibited' : 'required', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('promo_codes')->where('hotel_id', $hotelId)],
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'in:percent,amount'],
            'value' => ['required', 'numeric', 'gt:0', $request->input('type') === 'percent' ? 'max:90' : 'max:100000000'],
            'book_from' => ['nullable', 'date'], 'book_to' => ['nullable', 'date', 'after_or_equal:book_from'],
            'stay_from' => ['nullable', 'date'], 'stay_to' => ['nullable', 'date', 'after_or_equal:stay_from'],
            'min_nights' => ['sometimes', 'integer', 'between:1,60'], 'max_uses' => ['nullable', 'integer', 'min:1'],
            'room_type_ids' => ['nullable', 'array'], 'room_type_ids.*' => ['integer'], 'rate_plan_ids' => ['nullable', 'array'], 'rate_plan_ids.*' => ['integer'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($promo) {
            $promo->update($data);
        } else {
            $data['code'] = strtoupper($data['code']);
            $promo = PromoCode::create($data);
        }

        return response()->json($promo->refresh(), $request->isMethod('post') ? 201 : 200);
    }

    public function contracts(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');

        return ['data' => CorporateContract::with('company:id,name', 'ratePlan:id,code,name,derived_adjust_percent', 'allotments')->orderByDesc('id')->get()];
    }

    public function saveContract(Request $request, CorporateService $svc, ?CorporateContract $contract = null)
    {
        $this->need($request, 'revenue.manage');
        if ($contract) {
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:150'], 'valid_to' => ['sometimes', 'date'], 'last_room_availability' => ['sometimes', 'boolean'],
                'active' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            $contract->update($data);

            return $contract->refresh();
        }
        $hotelId = app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId();
        $data = $request->validate([
            'company_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('corporate_contracts')->where('hotel_id', $hotelId)],
            'name' => ['required', 'string', 'max:150'], 'rate_plan_id' => ['nullable', 'integer', 'required_without:parent_rate_plan_id'],
            'parent_rate_plan_id' => ['nullable', 'integer'], 'discount_percent' => ['nullable', 'numeric', 'between:0,90'],
            'valid_from' => ['required', 'date'], 'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'last_room_availability' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($svc->createContract($data)->load('ratePlan'), 201);
    }

    public function addAllotment(Request $request, CorporateContract $contract, CorporateService $svc)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'], 'rooms' => ['required', 'integer', 'between:1,200'],
            'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'release_days' => ['sometimes', 'integer', 'between:0,90'],
        ]);

        return response()->json($svc->addAllotment($contract, $data['room_type_id'], $data['rooms'], $data['start_date'], $data['end_date'], $data['release_days'] ?? 0), 201);
    }

    public function releaseAllotments(Request $request, CorporateService $svc)
    {
        $this->need($request, 'revenue.manage');

        return ['rooms_released' => $svc->releaseDue()];
    }

    public function availability(Request $request, CorporateContract $contract, CorporateService $svc)
    {
        $this->need($request, 'revenue.view', 'revenue.manage', 'reservations.manage');
        $data = $request->validate(['room_type_id' => ['required', 'integer'], 'arrival' => ['required', 'date'], 'departure' => ['required', 'date', 'after:arrival']]);

        return $svc->availability($contract, $data['room_type_id'], Carbon::parse($data['arrival']), Carbon::parse($data['departure']));
    }
}

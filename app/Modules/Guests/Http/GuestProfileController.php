<?php

namespace App\Modules\Guests\Http;

use App\Modules\Guests\Application\PrivacyService;
use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\CompanyContact;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Guests\Domain\PrivacyRequest;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Group-level read models for the guest and company detail pages, company
 * contacts, and data-protection requests. Reads span every hotel of the
 * group the user may access (not only the active one), never other groups.
 */
class GuestProfileController
{
    public function __construct(private TenantContext $ctx) {}

    /** Stay history across the user's allowed hotels. */
    public function history(Request $request, string $id)
    {
        $this->can($request, 'view');
        $guest = Guest::findOrFail($id);

        return $this->scoped(DB::table('reservations as r'), 'r')
            ->join('hotels as h', 'h.id', '=', 'r.hotel_id')
            ->leftJoin('rooms as rm', 'rm.id', '=', 'r.room_id')
            ->leftJoin('room_types as rt', 'rt.id', '=', 'r.room_type_id')
            ->where('r.guest_id', $guest->id)
            ->orderByDesc('r.arrival')
            ->limit(200)
            ->get(['r.id', 'r.number', 'r.status', 'r.arrival', 'r.departure', 'r.total_amount', 'r.channel', 'r.hotel_id',
                'h.name as hotel_name', 'h.code as hotel_code', 'rm.number as room_number', 'rt.name as room_type']);
    }

    /** Folios and balances across the user's allowed hotels. */
    public function folios(Request $request, string $id)
    {
        $this->can($request, 'view');
        $guest = Guest::findOrFail($id);
        $rows = $this->scoped(DB::table('folios as f'), 'f')
            ->join('hotels as h', 'h.id', '=', 'f.hotel_id')
            ->where('f.guest_id', $guest->id)
            ->orderByDesc('f.id')->limit(200)
            ->get(['f.id', 'f.number', 'f.kind', 'f.status', 'f.balance', 'f.reservation_id', 'f.hotel_id', 'h.name as hotel_name', 'f.created_at']);

        return ['folios' => $rows, 'open_balance' => (int) $rows->where('status', 'open')->sum('balance')];
    }

    /** Read-only loyalty membership summary (group-wide). */
    public function loyalty(Request $request, string $id)
    {
        $this->can($request, 'view');
        $guest = Guest::findOrFail($id);
        $m = DB::table('loyalty_memberships as m')
            ->leftJoin('loyalty_tiers as t', 't.id', '=', 'm.loyalty_tier_id')
            ->where('m.group_id', $this->ctx->groupId())->where('m.guest_id', $guest->id)
            ->first(['m.id', 'm.number', 'm.status', 'm.points_balance', 'm.lifetime_points', 'm.lifetime_nights', 'm.enrolled_at', 't.name as tier']);

        return ['membership' => $m];
    }

    /** Company detail: contacts, negotiated rate plans, bookings and the statement link. */
    public function company(Request $request, string $id)
    {
        $this->can($request, 'view');
        $company = Company::findOrFail($id);

        return [
            'company' => $company,
            'contacts' => CompanyContact::where('company_id', $company->id)->orderByDesc('primary')->orderBy('name')->get(),
            'rate_plans' => $this->scoped(DB::table('rate_plans as p'), 'p')->join('hotels as h', 'h.id', '=', 'p.hotel_id')
                ->where('p.company_id', $company->id)->get(['p.id', 'p.code', 'p.name', 'p.meal_plan', 'p.active', 'h.name as hotel_name']),
            'bookings' => $this->scoped(DB::table('reservations as r'), 'r')->join('hotels as h', 'h.id', '=', 'r.hotel_id')
                ->join('guests as g', 'g.id', '=', 'r.guest_id')
                ->where(fn ($q) => $q->where('r.company_id', $company->id)->orWhere('r.agent_id', $company->id))
                ->orderByDesc('r.arrival')->limit(100)
                ->get(['r.id', 'r.number', 'r.status', 'r.arrival', 'r.departure', 'r.total_amount', 'h.name as hotel_name',
                    DB::raw("g.first_name || ' ' || g.last_name as guest_name")]),
            'statement_url' => $company->city_ledger ? "receivables/companies/{$company->id}/statement" : null,
        ];
    }

    public function storeContact(Request $request, string $id)
    {
        $this->can($request, 'manage');
        $company = Company::findOrFail($id);
        $data = $request->validate($this->contactRules());

        return response()->json(CompanyContact::create($data + ['company_id' => $company->id]), 201);
    }

    public function updateContact(Request $request, string $id, string $contact)
    {
        $this->can($request, 'manage');
        $c = CompanyContact::where('company_id', $id)->findOrFail($contact);
        $c->update($request->validate($this->contactRules(true)));

        return $c;
    }

    public function destroyContact(Request $request, string $id, string $contact)
    {
        $this->can($request, 'manage');
        CompanyContact::where('company_id', $id)->findOrFail($contact)->delete();

        return response()->noContent();
    }

    public function privacyRequests(Request $request)
    {
        $this->can($request, 'manage');

        return PrivacyRequest::with(['guest:id,first_name,last_name,deleted_at', 'requester:id,name', 'decider:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('guest_id'), fn ($q, $v) => $q->where('guest_id', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function requestPrivacy(Request $request, PrivacyService $svc, string $id)
    {
        $this->can($request, 'manage');
        $guest = Guest::findOrFail($id);
        $data = $request->validate(['kind' => ['required', Rule::in(['anonymise', 'delete'])], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json($svc->request($guest, $data['kind'], $data['reason']), 201);
    }

    public function approvePrivacy(Request $request, PrivacyService $svc, string $req)
    {
        abort_unless($request->user()->can('guests.privacy_approve'), 403, 'Approving data-protection requests needs guests.privacy_approve.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $svc->approve(PrivacyRequest::findOrFail($req), $data['note'] ?? null);
    }

    public function rejectPrivacy(Request $request, PrivacyService $svc, string $req)
    {
        abort_unless($request->user()->can('guests.privacy_approve'), 403, 'Rejecting data-protection requests needs guests.privacy_approve.');
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $svc->reject(PrivacyRequest::findOrFail($req), $data['note']);
    }

    private function contactRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:150'],
            'role' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'primary' => ['sometimes', 'boolean'],
            'guest_id' => ['nullable', 'integer', Rule::exists('guests', 'id')->where('group_id', $this->ctx->groupId())],
        ];
    }

    /** Limit a hotel-owned table to the group and the hotels this user may see (ignores the active hotel). */
    private function scoped(Builder $q, string $alias): Builder
    {
        $q->where("$alias.group_id", $this->ctx->groupId() ?? 0);
        $actor = $this->ctx->actor();
        $allowed = $actor ? $actor->allowedHotelIds() : $this->ctx->allowedHotelIds();
        if ($allowed !== null) {
            $q->whereIn("$alias.hotel_id", $allowed);
        }

        return $q;
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('guests.manage') || ($ability === 'view' && $u->can('guests.view')), 403, 'You do not have permission for this action.');
    }
}

<?php

namespace App\Modules\Guests\Http;

use App\Modules\Guests\Domain\Guest;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Http\ResourceController;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GuestController extends ResourceController
{
    protected string $model = Guest::class;

    protected string $permission = 'guests';

    protected string $event = 'crm.guest';

    protected array $searchable = ['first_name', 'last_name', 'email', 'phone', 'id_number'];

    protected array $filterable = ['vip', 'blacklisted', 'nationality', 'company_id'];

    protected array $with = ['company:id,name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return [
            'title' => ['nullable', 'string', 'max:10'],
            'first_name' => [$req, 'string', 'max:100'],
            'last_name' => [$req, 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', Rule::in(['M', 'F', 'X'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'country' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'language' => ['sometimes', Rule::in(['fr', 'en'])],
            'id_type' => ['nullable', Rule::in(['passport', 'national_id', 'residence_permit', 'driving_licence', 'other'])],
            'id_number' => ['nullable', 'string', 'max:50'],
            'id_expiry' => ['nullable', 'date'],
            'id_issued_in' => ['nullable', 'string', 'size:2'],
            'profession' => ['nullable', 'string', 'max:100'],
            'vip' => ['sometimes', 'boolean'],
            'blacklisted' => ['sometimes', 'boolean'],
            'blacklist_reason' => ['nullable', 'string', 'required_if_accepted:blacklisted'],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('group_id', app(TenantContext::class)->groupId())],
            'preferences' => ['sometimes', 'array'],
            'notes' => ['nullable', 'string'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        if (array_key_exists('marketing_consent', $data)) {
            $data['consent_at'] = now();
        }

        return $data;
    }

    /** Possible duplicates by email, phone, ID number or exact name. */
    public function duplicates(Request $request, string $id)
    {
        $this->authorize($request, 'view');
        $g = Guest::findOrFail($id);

        return Guest::where('id', '!=', $g->id)->whereNull('merged_into_id')
            ->where(function ($q) use ($g) {
                if ($g->email) {
                    $q->orWhere('email', $g->email);
                }
                if ($g->phone) {
                    $q->orWhere('phone', $g->phone);
                }
                if ($g->id_number) {
                    $q->orWhere('id_number', $g->id_number);
                }
                $q->orWhere(fn ($w) => $w->where('first_name', 'ilike', $g->first_name)->where('last_name', 'ilike', $g->last_name));
            })->limit(20)->get();
    }

    /** Merge duplicate into survivor; history (reservations, stays, loyalty) follows via the event. */
    public function merge(Request $request, EventBus $bus, string $id)
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['duplicate_id' => ['required', 'integer']]);
        if ((int) $data['duplicate_id'] === (int) $id) {
            throw new DomainException('A guest cannot be merged into itself.', 'guest-self-merge');
        }
        $survivor = Guest::findOrFail($id);
        $dup = Guest::findOrFail($data['duplicate_id']);

        DB::transaction(function () use ($survivor, $dup, $bus) {
            foreach (['email', 'phone', 'id_type', 'id_number', 'id_expiry', 'nationality', 'date_of_birth', 'address', 'city'] as $f) {
                $survivor->$f ??= $dup->$f;
            }
            $survivor->vip = $survivor->vip || $dup->vip;
            $survivor->company_id ??= $dup->company_id;
            $survivor->save();

            // Re-point the duplicate's history at the survivor (both are already tenant-checked above).
            foreach (['reservations', 'waitlist_entries', 'folios', 'invoices', 'guest_register_entries', 'pos_checks', 'lost_found_items'] as $table) {
                DB::table($table)->where('guest_id', $dup->id)->update(['guest_id' => $survivor->id]);
            }
            // One loyalty membership per guest: move it, or fold its points into the survivor's.
            $dupMember = DB::table('loyalty_memberships')->where('guest_id', $dup->id)->first();
            if ($dupMember) {
                $survMember = DB::table('loyalty_memberships')->where('guest_id', $survivor->id)->first();
                if (! $survMember) {
                    DB::table('loyalty_memberships')->where('id', $dupMember->id)->update(['guest_id' => $survivor->id]);
                } else {
                    DB::table('loyalty_memberships')->where('id', $survMember->id)->update([
                        'points_balance' => $survMember->points_balance + $dupMember->points_balance,
                        'lifetime_points' => $survMember->lifetime_points + $dupMember->lifetime_points,
                        'lifetime_nights' => $survMember->lifetime_nights + $dupMember->lifetime_nights,
                    ]);
                    DB::table('loyalty_memberships')->where('id', $dupMember->id)->update(['points_balance' => 0, 'status' => 'closed']);
                }
            }
            $dup->forceFill(['merged_into_id' => $survivor->id])->save();
            $dup->delete();
            $bus->record('crm.guest.merged', ['survivor_id' => $survivor->id, 'merged_id' => $dup->id], 'guest', $survivor->id);
        });

        return $survivor->fresh($this->with);
    }

    /** Data-protection export of everything held about a guest. */
    public function export(Request $request, string $id)
    {
        $this->authorize($request, 'manage');
        $g = Guest::findOrFail($id);

        return response()->json([
            'profile' => $g,
            'reservations' => DB::table('reservations')->where('guest_id', $g->id)->get(),
            'loyalty' => DB::table('loyalty_memberships')->where('guest_id', $g->id)->get(),
        ], 200, ['Content-Disposition' => 'attachment; filename="guest-'.$g->id.'-export.json"']);
    }
}

<?php

namespace App\Modules\Tenancy\Http;

use App\Models\User;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Staff users of a group, with role and hotel scope. */
class UserController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('users.manage'), 403);

        return User::where('group_id', $request->user()->group_id)
            ->with(['roles:id,name', 'hotels:hotels.id,code,name'])
            ->orderBy('name')->paginate(50);
    }

    public function store(Request $request, EventBus $bus)
    {
        abort_unless($request->user()->can('users.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['sometimes', Rule::in(['fr', 'en'])],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'all_hotels' => ['boolean'],
            'hotel_ids' => ['array'],
            'hotel_ids.*' => ['integer'],
            'password' => ['nullable', 'string', 'min:10'],
        ]);

        $this->assertWithinActorScope($request->user(), $data);

        return DB::transaction(function () use ($data, $request, $bus) {
            $hotelIds = Hotel::whereIn('id', $data['hotel_ids'] ?? [])->pluck('id');
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? 'fr',
                'group_id' => $request->user()->group_id,
                'all_hotels' => $data['all_hotels'] ?? false,
                'password' => $data['password'] ?? Str::password(20),
            ]);
            $user->hotels()->sync($hotelIds);
            $user->syncRoles($data['roles']);
            $bus->record('identity.user.invited', ['user_id' => $user->id, 'roles' => $data['roles'], 'hotel_ids' => $hotelIds], 'user', $user->id);

            return response()->json($user->load('roles:id,name', 'hotels:hotels.id,code,name'), 201);
        });
    }

    public function update(Request $request, User $user, EventBus $bus)
    {
        abort_unless($request->user()->can('users.manage') && $user->group_id === $request->user()->group_id, 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'all_hotels' => ['sometimes', 'boolean'],
            'hotel_ids' => ['sometimes', 'array'],
        ]);
        $this->assertWithinActorScope($request->user(), $data);

        return DB::transaction(function () use ($user, $data, $bus) {
            $user->update(collect($data)->only(['name', 'status', 'all_hotels'])->all());
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
            if (isset($data['hotel_ids'])) {
                $user->hotels()->sync(Hotel::whereIn('id', $data['hotel_ids'])->pluck('id'));
            }
            if (($data['status'] ?? null) === 'suspended') {
                // Close every door at once: API tokens, live web sessions and remember-me cookies (SEC1 audit).
                $user->tokens()->update(['revoked' => true]);
                $user->forceFill(['remember_token' => Str::random(60)])->save();
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
            }
            $bus->record('identity.user.updated', ['user_id' => $user->id], 'user', $user->id);

            return $user->load('roles:id,name', 'hotels:hotels.id,code,name');
        });
    }

    public function roles()
    {
        return Role::where('guard_name', 'api')->with('permissions:id,name')->get();
    }

    /**
     * A hotel-scoped administrator may only hand out what they hold themselves (SEC1 audit):
     * no all_hotels grant, no hotels outside their own allow-list, no role they do not have.
     */
    private function assertWithinActorScope(User $actor, array $data): void
    {
        $allowed = $actor->allowedHotelIds();
        if ($allowed !== null) {
            abort_if(! empty($data['all_hotels']), 403, 'Only a group-wide administrator can grant access to every hotel.');
            foreach ($data['hotel_ids'] ?? [] as $id) {
                abort_unless(in_array((int) $id, $allowed, true), 403, 'You cannot grant access to a hotel outside your own scope.');
            }
            foreach ($data['roles'] ?? [] as $role) {
                abort_unless($actor->hasRole($role), 403, 'You cannot assign a role you do not hold yourself.');
            }
        }
    }
}

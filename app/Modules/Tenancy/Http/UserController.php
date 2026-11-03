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

        return DB::transaction(function () use ($user, $data, $bus) {
            $user->update(collect($data)->only(['name', 'status', 'all_hotels'])->all());
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
            if (isset($data['hotel_ids'])) {
                $user->hotels()->sync(Hotel::whereIn('id', $data['hotel_ids'])->pluck('id'));
            }
            if (($data['status'] ?? null) === 'suspended') {
                $user->tokens()->update(['revoked' => true]);
            }
            $bus->record('identity.user.updated', ['user_id' => $user->id], 'user', $user->id);

            return $user->load('roles:id,name', 'hotels:hotels.id,code,name');
        });
    }

    public function roles()
    {
        return Role::where('guard_name', 'api')->with('permissions:id,name')->get();
    }
}

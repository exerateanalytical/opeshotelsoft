<?php

namespace App\Modules\Tenancy\Http;

use App\Models\User;
use App\Modules\Admin\Application\TwoFactor;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrator password and two-factor resets for staff users (GAPH).
 *
 * Guards: users.manage, same group, inside the actor's hotel scope, never a user who outranks the actor
 * or holds a permission the actor lacks, never a platform administrator unless the actor is one.
 * Effects: every API token revoked, web sessions and remember-me cookies closed. Each reset is written to
 * the audit log (actor, target, reason) and the event outbox. The temporary password is returned once in
 * the response body and never logged, emailed (MAIL_MAILER=log would write it to disk) or stored in clear.
 */
class CredentialResetController
{
    /** Higher number = more privilege. Owner and general manager hold '*', so only rank separates them. */
    private const RANK = ['group-owner' => 100, 'general-manager' => 90, 'hotel-owner' => 60, 'finance-director' => 50];

    public function resetPassword(Request $request, User $user, EventBus $bus)
    {
        $actor = $request->user();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOn($actor, $user);
        abort_if($actor->is($user), 403, 'Use your own profile to change your password.');

        $temporary = $this->temporaryPassword();
        DB::transaction(function () use ($actor, $user, $temporary, $data, $bus) {
            $user->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();
            $this->closeAllAccess($user);
            $this->audit($actor, $user, 'password_reset', $data['reason']);
            $bus->record('identity.user.password_reset', ['user_id' => $user->id, 'by' => $actor->id], 'user', $user->id);
        });

        return response()->json([
            'user_id' => $user->id,
            'temporary_password' => $temporary,
            'must_change_password' => true,
        ])->header('Cache-Control', 'no-store');
    }

    public function resetTwoFactor(Request $request, User $user, EventBus $bus, TwoFactor $twoFactor)
    {
        $actor = $request->user();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOn($actor, $user);
        abort_if($actor->is($user), 403, 'You cannot reset your own two-factor authentication.');

        DB::transaction(function () use ($actor, $user, $data, $bus, $twoFactor) {
            $twoFactor->disable($user);
            $user->forceFill(['must_enrol_mfa' => true, 'mfa_last_step' => null])->save();
            $this->closeAllAccess($user);
            $this->audit($actor, $user, 'two_factor_reset', $data['reason']);
            $bus->record('identity.user.two_factor_reset', ['user_id' => $user->id, 'by' => $actor->id], 'user', $user->id);
        });

        return response()->json(['user_id' => $user->id, 'mfa_enabled' => false, 'must_enrol_mfa' => true]);
    }

    private function authorizeOn(User $actor, User $target): void
    {
        abort_unless($actor->can('users.manage') && $target->group_id === $actor->group_id, 403);
        abort_if($target->is_platform_admin && ! $actor->is_platform_admin, 403, 'Only a platform administrator can reset this account.');
        abort_if($this->rank($target) > $this->rank($actor), 403, 'You cannot reset the credentials of a user with a higher role than yours.');

        $mine = $actor->getAllPermissions()->pluck('name');
        $extra = $target->getAllPermissions()->pluck('name')->diff($mine);
        abort_if($extra->isNotEmpty(), 403, 'You cannot reset the credentials of a user who holds permissions you do not have.');

        // A hotel-scoped administrator may only act on users whose hotels all sit inside their own scope.
        $allowed = $actor->allowedHotelIds();
        if ($allowed !== null) {
            abort_if($target->all_hotels, 403, 'Only a group-wide administrator can reset a group-wide user.');
            $outside = array_diff($target->allowedHotelIds() ?? [], $allowed);
            abort_if($outside !== [], 403, 'This user has access to hotels outside your scope.');
        }
    }

    private function rank(User $user): int
    {
        return (int) collect($user->getRoleNames())->map(fn ($r) => self::RANK[$r] ?? 10)->max();
    }

    /** API tokens, refresh tokens, live web sessions and remember-me cookies (same doors as suspension). */
    private function closeAllAccess(User $user): void
    {
        // Straight on the table, by user id: $user->tokens() only sees tokens of clients bound to this provider.
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $user->id);
        $ids = (clone $tokens)->pluck('id');
        $tokens->update(['revoked' => true]);
        if ($ids->isNotEmpty()) {
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
        }
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database' || \Illuminate\Support\Facades\Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /** Actor, target and reason only: never the temporary password, secret or recovery codes. */
    private function audit(User $actor, User $target, string $event, string $reason): void
    {
        activity('security')
            ->causedBy($actor)
            ->performedOn($target)
            ->event($event)
            ->withProperties(['target_id' => $target->id, 'target_email' => $target->email, 'reason' => $reason, 'ip' => request()->ip()])
            ->log($event);
    }

    /** 16 characters from a CSPRNG with letters, digits and symbols (Str::password includes each class). */
    private function temporaryPassword(): string
    {
        return Str::password(16);
    }
}

<?php

namespace App\Http\Controllers;

use App\Modules\Admin\Application\TwoFactor;
use App\Modules\Admin\Domain\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ProfileController
{
    public function show(Request $request, TwoFactor $twoFactor)
    {
        $user = $request->user();
        $enabled = (bool) $twoFactor->enabled($user);
        $pending = ! $enabled && $user->mfa_secret;

        return Inertia::render('Profile/Index', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'locale' => app()->getLocale(),
            ],
            'twoFactor' => [
                'enabled' => $enabled,
                'required' => $twoFactor->required($user),
                'pending' => (bool) $pending,
                'secret' => $pending ? $user->mfa_secret : null,
                'otpauth' => $pending ? Totp::uri($user->mfa_secret, $user->email) : null,
                'remaining_codes' => $enabled ? $twoFactor->remainingCodes($user) : 0,
                'recovery_codes' => $request->session()->get('recovery_codes'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $request->user()->update($data);

        return back()->with('success', __('profile.saved'));
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', __('profile.password_changed'));
    }
}

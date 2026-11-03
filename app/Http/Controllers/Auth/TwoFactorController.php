<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Modules\Admin\Application\TwoFactor;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Login challenge and self-service enrolment (Profile) for TOTP two-factor authentication. */
class TwoFactorController
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function challenge(Request $request)
    {
        if (! $request->session()->has('2fa.user_id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = User::find($request->session()->get('2fa.user_id'));
        if (! $user) {
            return redirect()->route('login');
        }
        if (! $this->twoFactor->check($user, $data['code'])) {
            app(\App\Modules\Admin\Application\LoginGuard::class)->failed($user->email, $request->ip(), $user, 'two-factor');
            throw ValidationException::withMessages(['code' => __('auth.2fa.invalid_code')]);
        }
        $remember = (bool) $request->session()->pull('2fa.remember', false);
        $request->session()->forget('2fa.user_id');
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        app(\App\Modules\Admin\Application\LoginGuard::class)->succeeded($user->email, $request->ip(), $user, 'web-2fa');

        return redirect()->intended(route('dashboard'));
    }

    public function enable(Request $request)
    {
        $this->twoFactor->begin($request->user());

        return back()->with('success', __('profile.2fa.scan'));
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        try {
            $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back()->with('success', __('profile.2fa.enabled'))->with('recovery_codes', $codes);
    }

    public function regenerate(Request $request)
    {
        abort_unless($this->twoFactor->enabled($request->user()), 422);

        return back()->with('recovery_codes', $this->twoFactor->regenerateCodes($request->user()));
    }

    public function disable(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password:web']]);
        if ($this->twoFactor->required($request->user())) {
            return back()->with('error', __('profile.2fa.cannot_disable'));
        }
        $this->twoFactor->disable($request->user());

        return back()->with('success', __('profile.2fa.disabled'));
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Application\LoginGuard;
use App\Modules\Admin\Application\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(Request $request, TwoFactor $twoFactor)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $guard = app(LoginGuard::class);
        $user = User::where('email', $credentials['email'])->first();
        if ($guard->throttled($credentials['email'], $request->ip())) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => $guard->retryAfter($credentials['email'], $request->ip())])])->status(429);
        }
        if ($guard->locked($user)) {
            $guard->blocked($user, $request->ip());
            throw ValidationException::withMessages(['email' => __('This account is temporarily locked after repeated failed sign-ins.')])->status(423);
        }
        // Suspended accounts are refused exactly like a wrong password (same rule as ApiTokenIssuer; SEC1 audit).
        if (! Auth::guard('web')->validate($credentials) || $user->status !== 'active') {
            $guard->failed($credentials['email'], $request->ip(), $user);
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Enrolled users must pass the TOTP challenge before the session is authenticated.
        if ($twoFactor->enabled($user)) {
            $request->session()->regenerate(); // a pre-login session id must not survive the password step (SEC1 audit)
            $request->session()->put(['2fa.user_id' => $user->id, '2fa.remember' => $request->boolean('remember')]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $guard->succeeded($user->email, $request->ip(), $user, 'web');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

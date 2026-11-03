<?php

namespace App\Modules\MemberPortal\Http;

use App\Modules\MemberPortal\Application\MemberAuthService;
use App\Modules\MemberPortal\Domain\MemberAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** Sign-up, sign-in (password or one-time code), password reset and sign-out for loyalty-club members. JSON in, JSON out. */
class MemberAuthController
{
    public function __construct(private MemberAuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        $d = $request->validate([
            'contact' => ['required', 'string', 'max:190'], 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'locale' => ['nullable', 'in:fr,en'], 'marketing_consent' => ['sometimes', 'boolean'], 'group' => ['nullable', 'string', 'max:100'],
        ]);
        $this->auth->register($d, $d['group'] ?? null);

        return response()->json(['status' => 'code_sent']); // identical whether or not the contact already has an account
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $d = $request->validate([
            'contact' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:12'], 'group' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        return $this->signedIn($request, $this->auth->verifySignup($d['contact'], $d['code'], $d['password'], $d['group'] ?? null));
    }

    public function login(Request $request): JsonResponse
    {
        $d = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:200']]);

        return $this->signedIn($request, $this->auth->attemptPassword($d['identifier'], $d['password'], $request->ip()));
    }

    public function sendCode(Request $request): JsonResponse
    {
        $d = $request->validate(['identifier' => ['required', 'string', 'max:190']]);
        $this->auth->sendLoginCode($d['identifier']);

        return response()->json(['status' => 'code_sent']);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $d = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:12']]);

        return $this->signedIn($request, $this->auth->attemptCode($d['identifier'], $d['code'], $request->ip()));
    }

    public function forgot(Request $request): JsonResponse
    {
        $d = $request->validate(['identifier' => ['required', 'string', 'max:190']]);
        $this->auth->sendResetCode($d['identifier']);

        return response()->json(['status' => 'code_sent']);
    }

    public function reset(Request $request): JsonResponse
    {
        $d = $request->validate([
            'identifier' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $this->auth->reset($d['identifier'], $d['code'], $d['password'], $request->ip());

        return response()->json(['status' => 'password_changed']);
    }

    public function logout(Request $request)
    {
        Auth::guard('member')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login?tab=member');
    }

    /** Start the member session (and end any staff session in this browser: the two worlds never mix). */
    public static function startSession(Request $request, MemberAccount $account): void
    {
        Auth::guard('web')->logout();
        Auth::guard('member')->login($account);
        $request->session()->regenerate();
        $request->session()->put('locale', $account->locale);
    }

    private function signedIn(Request $request, MemberAccount $account): JsonResponse
    {
        self::startSession($request, $account);

        return response()->json(['status' => 'ok', 'redirect' => '/account']);
    }
}

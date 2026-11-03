<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleController;
use App\Http\Navigation;
use App\Modules\Admin\Application\TwoFactor;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /** Routes a user who must still enrol in 2FA may reach. */
    private const MFA_ALLOWED = ['profile', 'profile.*', 'two-factor.*', 'logout', 'locale'];

    private static array $translations = [];

    public function handle(Request $request, Closure $next)
    {
        app()->setLocale($this->locale($request));

        $user = $this->staffUser($request);
        if ($user && $this->mustEnrol($request, $user) && ! $request->routeIs(...self::MFA_ALLOWED)) {
            return redirect()->route('profile')->with('error', __('auth.2fa.required'));
        }

        return parent::handle($request, $next);
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $this->staffUser($request);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames() ?? [],
                    'permissions' => $user->getAllPermissions()->pluck('name') ?? [],
                ] : null,
            ],
            // Sidebar entries this user may see (App\Http\Navigation).
            'nav' => $user ? Navigation::for($user) : [],
            'locale' => app()->getLocale(),
            'translations' => self::translations(app()->getLocale()),
            // Active hotel and network come from the signed-in user's scope (no hard-coded property).
            'property' => $user ? $this->activeHotel($request, $user) : null,
            'hotels' => $user ? $this->hotels($user) : [],
            'network' => $user?->group ? ['id' => $user->group->id, 'name' => $user->group->name, 'slug' => $user->group->slug] : null,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /** lang/<locale>.json plus the per-module files in lang/<locale>/*.json, cached per process. */
    public static function translations(string $locale): array
    {
        if (! isset(self::$translations[$locale])) {
            $all = json_decode((string) @file_get_contents(lang_path("$locale.json")), true) ?: [];
            foreach (glob(lang_path("$locale/*.json")) ?: [] as $file) {
                $all += json_decode((string) file_get_contents($file), true) ?: [];
            }
            self::$translations[$locale] = $all;
        }

        return self::$translations[$locale];
    }

    /** The signed-in staff user. The customer portal (/account) never sees one, even if the browser also holds a staff session. */
    private function staffUser(Request $request)
    {
        return $request->is("account", "account/*") ? null : $request->user();
    }

    private function locale(Request $request): string
    {
        $wanted = $this->staffUser($request)?->locale ?? ($request->hasSession() ? $request->session()->get('locale') : null);

        return in_array($wanted, LocaleController::SUPPORTED, true) ? $wanted : LocaleController::DEFAULT;
    }

    /** Required role, not enrolled, and not a demo session while DEMO_LOGINS is on. */
    private function mustEnrol(Request $request, $user): bool
    {
        if (app()->runningUnitTests() && ! TwoFactor::$enforceInTests) {
            return false;
        }
        if (config('app.demo_logins') && $request->session()->get('demo_login')) {
            return false;
        }
        $mfa = app(TwoFactor::class);

        return $mfa->required($user) && ! $mfa->enabled($user);
    }

    private function hotels($user): array
    {
        return app(TenantContext::class)->withoutScope(fn () => Hotel::query()
            ->where('group_id', $user->group_id)
            ->when(! $user->all_hotels, fn ($q) => $q->whereIn('id', $user->allowedHotelIds() ?? []))
            ->orderBy('name')->get(['id', 'code', 'name', 'city', 'country', 'currency', 'timezone', 'stars'])->toArray());
    }

    private function activeHotel(Request $request, $user): ?array
    {
        $hotels = $this->hotels($user);
        // The switcher sets hotel_id from JavaScript, so it is unencrypted; fall back to the raw header value.
        $raw = $request->cookie('hotel_id');
        if (! $raw && preg_match('/(?:^|;\s*)hotel_id=(\d+)/', (string) $request->headers->get('cookie'), $m)) {
            $raw = $m[1];
        }
        $wanted = (int) $raw;

        return collect($hotels)->firstWhere('id', $wanted) ?? ($hotels[0] ?? null);
    }
}

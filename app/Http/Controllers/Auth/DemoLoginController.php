<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** One-click sign-in as a seeded demo account. Disabled unless config('app.demo_logins'). */
class DemoLoginController
{
    /** Accounts shown on the login page, grouped by section. */
    public static function accounts(): array
    {
        if (! config('app.demo_logins')) {
            return [];
        }
        $accounts = [['login' => 'admin', 'name' => 'Super Admin (System Administrator)', 'section' => 'Management']];
        foreach (DemoUsersSeeder::USERS as $login => [$name, , , $section]) {
            $accounts[] = compact('login', 'name', 'section');
        }

        return $accounts;
    }

    public function store(Request $request, string $login)
    {
        abort_unless(config('app.demo_logins'), 404);
        abort_unless($login === 'admin' || array_key_exists($login, DemoUsersSeeder::USERS), 404);

        $email = $login === 'admin' ? 'admin@opeshotelsoft.test' : "$login@demo.opeshotelsoft.test";
        $user = User::where('email', $email)->first()
            ?? abort(404, 'Demo account not seeded. Run: php artisan db:seed --class=DemoUsersSeeder');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // Demo sessions skip the 2FA requirement, but only while DEMO_LOGINS is on (checked on every request).
        $request->session()->put('demo_login', true);

        return redirect()->route('dashboard');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\MemberPortal\Http\MemberAuthController;
use App\Modules\Tenancy\Domain\Group;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** One-click sign-in as a seeded demo member of the demo group. Disabled unless config('app.demo_logins'). */
class DemoMemberLoginController
{
    private static function demo(): \Illuminate\Database\Eloquent\Builder
    {
        $group = Group::where('slug', 'opes-demo')->value('id');

        return MemberAccount::where('group_id', $group ?? 0)->whereNotNull('verified_at')->whereNotNull('guest_id');
    }

    /** Accounts shown on the Member tab. */
    public static function accounts(): array
    {
        if (! config('app.demo_logins')) {
            return [];
        }

        return app(TenantContext::class)->withoutScope(fn () => self::demo()->orderBy('id')->limit(20)->get()
            ->map(fn ($a) => ['n' => $a->id, 'name' => trim("$a->first_name $a->last_name")])->all());
    }

    public function store(Request $request, int $n)
    {
        abort_unless(config('app.demo_logins'), 404);
        $account = app(TenantContext::class)->withoutScope(fn () => self::demo()->find($n));
        abort_unless($account, 404, 'Demo member not seeded. Run: php artisan db:seed --class=DemoMembersSeeder');

        MemberAuthController::startSession($request, $account);

        return redirect('/account');
    }
}

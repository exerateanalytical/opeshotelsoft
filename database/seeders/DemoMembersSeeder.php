<?php

namespace Database\Seeders;

use App\Modules\Loyalty\Domain\Membership;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Tenancy\Domain\Group;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Portal accounts for 20 of the demo loyalty members (run DemoBulkSeeder first so members exist). Safe to re-run.
 * Password for all: Member@2026! (local demo only). Sign in from the Member tab, or with one click while DEMO_LOGINS is on.
 */
class DemoMembersSeeder extends Seeder
{
    public const PASSWORD = 'Member@2026!';

    public const COUNT = 20;

    public function run(): void
    {
        $ctx = app(TenantContext::class);
        $group = Group::where('slug', 'opes-demo')->firstOrFail();
        $ctx->set($group->id, null, null);

        $n = 0;
        foreach (Membership::with('guest')->orderBy('id')->limit(self::COUNT)->get() as $m) {
            $g = $m->guest;
            if (! $g || MemberAccount::where('guest_id', $g->id)->exists()) {
                continue;
            }
            $n++;
            $email = $g->email ? mb_strtolower($g->email) : null;
            if (! $email || MemberAccount::where('email', $email)->exists()) {
                $email = 'member'.$m->id.'@demo.opeshotelsoft.test';
            }
            MemberAccount::create([
                'guest_id' => $g->id, 'first_name' => $g->first_name, 'last_name' => $g->last_name, 'email' => $email,
                'verified_contact' => 'email', 'verified_at' => now(), 'password' => self::PASSWORD, 'locale' => $g->language === 'en' ? 'en' : 'fr',
            ]);
        }
        $this->command?->info("Member accounts created: $n (password ".self::PASSWORD.')');
    }
}

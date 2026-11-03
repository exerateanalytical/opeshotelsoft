<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * One demo login per staff role in the fictional demo group. Safe to re-run.
 * All use the password Demo@2026! except the administrator (Admin@2026!).
 * The login page lists these as one-click buttons when DEMO_LOGINS is on (never in production).
 */
class DemoUsersSeeder extends Seeder
{
    /** login => [display name, role, hotel codes (empty = all hotels), section on the login page] */
    public const USERS = [
        'network' => ['Network Administrator', 'group-owner', [], 'Management'],
        'owner' => ['Hotel Owner (Douala)', 'hotel-owner', ['DLA'], 'Management'],
        'gm' => ['General Manager (Douala)', 'general-manager', ['DLA'], 'Management'],
        'revenue' => ['Revenue Manager', 'revenue-manager', [], 'Management'],
        'fom' => ['Front Office Manager', 'front-office-manager', ['DLA'], 'Front office'],
        'reception' => ['Receptionist', 'receptionist', ['DLA'], 'Front office'],
        'reservations' => ['Reservations Agent', 'reservations-agent', ['DLA'], 'Front office'],
        'night' => ['Night Auditor', 'night-auditor', ['DLA'], 'Front office'],
        'fnb' => ['F&B Manager (Restaurant & Bar)', 'fnb-manager', ['DLA'], 'Restaurant & bar'],
        'cashier' => ['Restaurant / Bar Cashier', 'pos-cashier', ['DLA'], 'Restaurant & bar'],
        'store' => ['Storekeeper (Warehouse)', 'storekeeper', ['DLA'], 'Operations'],
        'hk' => ['Housekeeping Manager', 'housekeeping-manager', ['DLA'], 'Operations'],
        'attendant' => ['Room Attendant', 'room-attendant', ['DLA'], 'Operations'],
        'tech' => ['Maintenance Technician', 'maintenance-technician', ['DLA'], 'Operations'],
        'finance' => ['Finance Director', 'finance-director', [], 'Finance & HR'],
        'accountant' => ['Accountant', 'accountant', ['DLA'], 'Finance & HR'],
        'hr' => ['HR Officer', 'hr-officer', [], 'Finance & HR'],
        'payroll' => ['Payroll Officer', 'payroll-officer', [], 'Finance & HR'],
        'auditor' => ['Auditor (read-only)', 'auditor', [], 'Finance & HR'],
    ];

    public function run(): void
    {
        $ctx = app(TenantContext::class);
        $group = Group::where('slug', 'opes-demo')->firstOrFail();
        $hotels = $ctx->withoutScope(fn () => Hotel::where('group_id', $group->id)->pluck('id', 'code'));

        $admin = User::firstOrNew(['email' => 'admin@opeshotelsoft.test']);
        $admin->fill(['name' => 'System Administrator', 'group_id' => $group->id, 'all_hotels' => true, 'password' => $admin->exists ? $admin->password : 'Admin@2026!'])->save();
        $admin->syncRoles(['group-owner']);

        foreach (self::USERS as $login => [$name, $role, $codes]) {
            $u = User::firstOrCreate(['email' => "$login@demo.opeshotelsoft.test"], ['name' => $name, 'password' => 'Demo@2026!', 'group_id' => $group->id]);
            $u->update(['name' => $name, 'all_hotels' => $codes === []]);
            $u->syncRoles([$role]);
            $u->hotels()->sync(collect($codes)->map(fn ($c) => $hotels[$c]));
        }
    }
}

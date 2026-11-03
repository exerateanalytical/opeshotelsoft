<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission catalogue and default roles (SPEC §2). Every permission is
 * "<area>.view" or "<area>.manage", plus a few sensitive actions.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const AREAS = [
        'hotels', 'users', 'integrations', 'property', 'rates', 'reservations', 'frontdesk', 'folios', 'cashier',
        'guests', 'loyalty', 'housekeeping', 'maintenance', 'pos', 'stock', 'purchasing', 'accounting',
        'receivables', 'payables', 'hr', 'payroll', 'reports', 'channels',
    ];

    public const SPECIAL = [
        'reservations.waive_fees', 'folios.void', 'folios.adjust', 'frontdesk.night_audit', 'pos.void',
        'pos.discount', 'purchasing.approve', 'accounting.close_period', 'accounting.post_manual',
        'payroll.approve', 'loyalty.adjust_points', 'stock.adjust',
    ];

    public const ROLES = [
        'group-owner' => '*',
        'general-manager' => '*',
        'finance-director' => ['accounting.*', 'receivables.*', 'payables.*', 'folios.view', 'cashier.view', 'reports.*', 'payroll.view', 'stock.view', 'purchasing.*'],
        'accountant' => ['accounting.manage', 'accounting.post_manual', 'receivables.manage', 'payables.manage', 'folios.view', 'cashier.view', 'reports.view', 'stock.view', 'purchasing.view'],
        'revenue-manager' => ['rates.manage', 'reservations.view', 'channels.manage', 'reports.view', 'property.view'],
        'front-office-manager' => ['reservations.manage', 'reservations.waive_fees', 'frontdesk.manage', 'frontdesk.night_audit', 'folios.manage', 'folios.void', 'folios.adjust', 'cashier.manage', 'guests.manage', 'loyalty.manage', 'property.view', 'rates.view', 'housekeeping.view', 'reports.view'],
        'receptionist' => ['reservations.manage', 'frontdesk.manage', 'folios.manage', 'cashier.manage', 'guests.manage', 'loyalty.view', 'property.view', 'rates.view', 'housekeeping.view'],
        'night-auditor' => ['frontdesk.manage', 'frontdesk.night_audit', 'folios.manage', 'cashier.manage', 'reservations.manage', 'reports.view', 'property.view'],
        'reservations-agent' => ['reservations.manage', 'guests.manage', 'rates.view', 'property.view', 'loyalty.view'],
        'housekeeping-manager' => ['housekeeping.manage', 'property.view', 'maintenance.view', 'stock.view'],
        'room-attendant' => ['housekeeping.view'],
        'maintenance-technician' => ['maintenance.manage', 'property.view'],
        'pos-cashier' => ['pos.manage', 'guests.view'],
        'fnb-manager' => ['pos.manage', 'pos.void', 'pos.discount', 'stock.view', 'purchasing.view', 'reports.view'],
        'storekeeper' => ['stock.manage', 'stock.adjust', 'purchasing.manage'],
        'hr-officer' => ['hr.manage'],
        'payroll-officer' => ['hr.view', 'payroll.manage'],
        'auditor' => ['*.view'],
        'hotel-owner' => ['*.view', 'reports.*'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = [];
        foreach (self::AREAS as $area) {
            $all[] = "$area.view";
            $all[] = "$area.manage";
        }
        $all = array_merge($all, self::SPECIAL);
        // Feature permission files: return ['permissions' => [...], 'roles' => ['role' => [...]]].
        $roles = self::ROLES;
        foreach (glob(__DIR__.'/permissions/*.php') as $file) {
            $extra = (static fn (string $f) => require $f)($file); // isolated scope: files cannot clobber $roles
            $all = array_merge($all, $extra['permissions'] ?? []);
            foreach ($extra['roles'] ?? [] as $role => $grants) {
                if (($roles[$role] ?? null) !== '*') {
                    $roles[$role] = array_merge($roles[$role] ?? [], $grants);
                }
            }
        }
        $all = array_values(array_unique($all));
        foreach ($all as $name) {
            Permission::findOrCreate($name, 'api');
        }

        foreach ($roles as $role => $grants) {
            $perms = $grants === '*' ? $all : collect($grants)->flatMap(function ($g) use ($all) {
                if (str_starts_with($g, '*.')) {
                    return array_filter($all, fn ($p) => str_ends_with($p, substr($g, 1)));
                }
                if (str_ends_with($g, '.*')) {
                    return array_filter($all, fn ($p) => str_starts_with($p, substr($g, 0, -1)));
                }

                return [$g];
            })->unique()->values()->all();
            Role::findOrCreate($role, 'api')->syncPermissions($perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

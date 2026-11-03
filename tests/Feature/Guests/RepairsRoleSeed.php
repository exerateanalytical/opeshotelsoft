<?php

namespace Tests\Feature\Guests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Temporary guard for W08 tests: database/seeders/permissions/w01.php assigns a
 * top-level $roles, which overwrites the seeder's own $roles (require shares the
 * method scope), so the base roles are not created. If that happens, rebuild the
 * base roles here. Does nothing once the seeder is fixed.
 */
trait RepairsRoleSeed
{
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        $result = parent::seed($class);
        if ($class === RolesAndPermissionsSeeder::class && ! Role::where('name', 'general-manager')->where('guard_name', 'api')->exists()) {
            $all = Permission::where('guard_name', 'api')->pluck('name')->all();
            $roles = RolesAndPermissionsSeeder::ROLES;
            foreach (glob(base_path('database/seeders/permissions/*.php')) as $file) {
                $extra = (fn () => require $file)();
                foreach ($extra['roles'] ?? [] as $role => $grants) {
                    if (($roles[$role] ?? null) !== '*') {
                        $roles[$role] = array_merge($roles[$role] ?? [], $grants);
                    }
                }
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

        return $result;
    }
}

<?php

use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * W01 platform shell grants.
 * - Every "<area>.manage" grant implies "<area>.view", so page guards can use the .view permission.
 * - audit.view: audit log viewer. currencies.*: currencies and exchange rates (Admin module).
 * Wildcard roles (group-owner, general-manager) get everything; *.view roles (auditor, hotel-owner)
 * pick up audit.view and currencies.view automatically.
 */
$roles = [];
foreach (RolesAndPermissionsSeeder::ROLES as $role => $grants) {
    if ($grants === '*') {
        continue;
    }
    foreach ($grants as $g) {
        if (str_ends_with($g, '.manage')) {
            $roles[$role][] = substr($g, 0, -7).'.view';
        }
    }
}

$roles['finance-director'] = array_merge($roles['finance-director'] ?? [], ['audit.view', 'currencies.view', 'currencies.manage']);
$roles['accountant'] = array_merge($roles['accountant'] ?? [], ['currencies.view']);

return [
    'permissions' => ['audit.view', 'currencies.view', 'currencies.manage'],
    'roles' => $roles,
];

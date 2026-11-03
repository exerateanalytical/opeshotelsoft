<?php

// H-A Workforce.
// - workforce.view: forecast, rosters, anomalies, labour-cost dashboard (read).
// - workforce.manage: staffing rules, generate and edit draft rosters, leave policies and accruals, PINs, review anomalies.
// - workforce.publish: publish a roster and decide shift swaps and open-shift claims (cannot approve a request one is part of).
// - workforce.kiosk: the hotel's clock-in terminal account (PIN punches).
// Self-service (my shifts, swaps, claims, clock, my leave balance) needs only a user linked to an employee.
return [
    'permissions' => ['workforce.view', 'workforce.manage', 'workforce.publish', 'workforce.kiosk'],
    'roles' => [
        'hr-officer' => ['workforce.view', 'workforce.manage', 'workforce.publish'],
        'payroll-officer' => ['workforce.view'],
        'front-office-manager' => ['workforce.view', 'workforce.manage', 'workforce.publish', 'workforce.kiosk'],
        'housekeeping-manager' => ['workforce.view', 'workforce.manage', 'workforce.publish'],
        'fnb-manager' => ['workforce.view', 'workforce.manage', 'workforce.publish'],
        'finance-director' => ['workforce.view'],
    ],
];

<?php

// Q4 owner view and leakage alerts.
// - insights.view: owner page, alert inbox, digest history (also granted by the *.view pattern).
// - insights.manage: acknowledge/resolve alerts, rule thresholds, digest recipients, manual scan.
// - insights.group: group view across every hotel the user may see.
return [
    'permissions' => ['insights.view', 'insights.manage', 'insights.group'],
    'roles' => [
        'hotel-owner' => ['insights.view', 'insights.manage', 'insights.group'],
        'finance-director' => ['insights.view', 'insights.group'],
        'auditor' => ['insights.view'],
    ],
];

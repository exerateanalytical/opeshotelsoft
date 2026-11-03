<?php

return [
    'permissions' => ['payments.view', 'payments.manage', 'payments.refund', 'payments.settings'],
    'roles' => [
        'finance-director' => ['payments.view', 'payments.refund', 'payments.settings'],
        'accountant' => ['payments.view'],
        'front-office-manager' => ['payments.view', 'payments.manage', 'payments.refund'],
        'receptionist' => ['payments.view', 'payments.manage'],
        'night-auditor' => ['payments.view', 'payments.manage'],
        'reservations-agent' => ['payments.view', 'payments.manage'],
    ],
];

<?php

return [
    'permissions' => ['notifications.view', 'notifications.manage'],
    'roles' => [
        'front-office-manager' => ['notifications.view', 'notifications.manage'],
        'receptionist' => ['notifications.view'],
        'reservations-agent' => ['notifications.view'],
        'revenue-manager' => ['notifications.view'],
    ],
];

<?php

// V1 service requests and voice notifications.
// - servicerequests.view: the board at /service-requests and the voice settings page (also granted by *.view).
// - servicerequests.manage: manual (phone) entry, acknowledge, assign, start, complete, cancel.
// - servicerequests.voice: hear the spoken alerts in the staff layout.
// - servicerequests.settings: change the hotel's response-time targets (managers only; attendants run requests but cannot edit targets).
// Everyone who works a department gets the voice; leaders and desk staff also run the board.
return [
    'permissions' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice', 'servicerequests.settings'],
    'roles' => [
        'front-office-manager' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice', 'servicerequests.settings'],
        'receptionist' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice'],
        'night-auditor' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice'],
        'housekeeping-manager' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice', 'servicerequests.settings'],
        'room-attendant' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice'],
        'maintenance-technician' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice'],
        'fnb-manager' => ['servicerequests.view', 'servicerequests.manage', 'servicerequests.voice', 'servicerequests.settings'],
        'pos-cashier' => ['servicerequests.view', 'servicerequests.voice'],
    ],
];

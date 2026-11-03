<?php

// W08 guests and POS extras.
// - guests.privacy_approve: second-person approval of data-protection (anonymise/delete) requests.
// - pos.comp: supervisor approval of complimentary items/checks.
// - pos.configure: outlet set-up (floor-plan tables, price levels / happy hour).
return [
    'permissions' => ['guests.privacy_approve', 'pos.comp', 'pos.configure'],
    'roles' => [
        'front-office-manager' => ['guests.privacy_approve'],
        'fnb-manager' => ['pos.comp', 'pos.configure'],
    ],
];

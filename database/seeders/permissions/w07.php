<?php

// W07 front desk extras: configuring check-out fee rules.
return [
    'permissions' => ['frontdesk.configure'],
    'roles' => ['front-office-manager' => ['frontdesk.configure']],
];

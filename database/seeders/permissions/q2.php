<?php

// Q2 HR employment lifecycle.
// - hr.lifecycle.manage: generate/send e-contracts, request raises/promotions/transfers, add bonuses/allowances, service-charge pools.
// - hr.lifecycle.approve: approve raises/promotions/transfers, counter-sign contracts as employer, distribute service-charge pools.
//   Segregation of duties is enforced in code: the requester cannot approve their own request.
return [
    'permissions' => ['hr.lifecycle.manage', 'hr.lifecycle.approve'],
    'roles' => [
        'hr-officer' => ['hr.lifecycle.manage'],
        'payroll-officer' => ['hr.lifecycle.manage'],
    ],
];

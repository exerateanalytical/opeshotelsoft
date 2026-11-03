<?php

// P1 loyalty cards and promotions.
// - loyalty.cards: block, unblock, replace member cards.
// - loyalty.promotions: create and edit promotion rules (multiplier, birthday, enrolment bonus).
return [
    'permissions' => ['loyalty.cards', 'loyalty.promotions'],
    'roles' => [
        'front-office-manager' => ['loyalty.cards', 'loyalty.promotions'],
        'receptionist' => ['loyalty.cards'],
    ],
];

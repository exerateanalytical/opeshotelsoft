<?php

// R-B reservation experience.
// - experience.view: arrivals readiness board, room suggestions, registration cards, channel sync health, reviews, settings.
// - experience.manage: tape chart move/extend/split/swap, group bookings, rooming list import, capture ID and signature,
//   accept or reject room suggestions, set deposit/no-show/self-service rules and guest services.
return [
    'permissions' => ['experience.view', 'experience.manage'],
    'roles' => [
        'front-office-manager' => ['experience.view', 'experience.manage'],
        'receptionist' => ['experience.view', 'experience.manage'],
        'reservations-agent' => ['experience.view', 'experience.manage'],
        'night-auditor' => ['experience.view'],
        'revenue-manager' => ['experience.view'],
        'hotel-owner' => ['experience.view'],
    ],
];

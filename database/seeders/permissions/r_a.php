<?php

// R-A booking revenue engine.
// - revenue.view: read rules, suggestions, promos, contracts, overbooking and walks.
// - revenue.manage: stay rules, packages, pricing settings/events/competitor rates, promos, contracts, allotments, overbooking caps.
// - revenue.approve: accept or reject dynamic-pricing suggestions (a suggestion never changes a rate by itself).
// - revenue.upsell: offer and accept room upgrades, walk a guest.
return [
    'permissions' => ['revenue.view', 'revenue.manage', 'revenue.approve', 'revenue.upsell'],
    'roles' => [
        'revenue-manager' => ['revenue.view', 'revenue.manage', 'revenue.approve', 'revenue.upsell'],
        'front-office-manager' => ['revenue.view', 'revenue.upsell'],
        'receptionist' => ['revenue.upsell'],
        'reservations-agent' => ['revenue.upsell'],
        'hotel-owner' => ['revenue.view'],
    ],
];

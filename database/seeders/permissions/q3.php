<?php

// Q3 People: performance reviews, guest feedback on staff, tasks, discipline, exits.
// - people.view: see reviews, feedback, tasks of the hotel.
// - people.manage: run review cycles, objectives, calibration, record feedback.
// - people.tasks: create, assign, cancel tasks (assignees act on their own tasks without it).
// - people.discipline: issue and manage warnings and suspensions.
// - people.exit: exits, final settlement, work certificate.
return [
    'permissions' => ['people.view', 'people.manage', 'people.tasks', 'people.discipline', 'people.exit'],
    'roles' => [
        'hr-officer' => ['people.view', 'people.manage', 'people.tasks', 'people.discipline', 'people.exit'],
        'payroll-officer' => ['people.view', 'people.exit'],
        'front-office-manager' => ['people.tasks'],
        'housekeeping-manager' => ['people.tasks'],
        'fnb-manager' => ['people.tasks'],
    ],
];

<?php

// H-B talent and compliance.
// - talent.view: read recruitment, skills, training, planning screens.
// - talent.recruit: job posts, pipeline, interviews, offers. talent.interview: score interviews you sit on, read CVs.
// - talent.manage: skills matrix, certificates, courses, sessions, headcount budgets, succession.
// - talent.requests: decide staff self-service requests (certificates, address, phone, bank, MoMo).
// - talent.payroll_review: review and acknowledge payroll checks, configure them.
// - talent.privacy: employee data export and erasure, applicant anonymisation, retention settings.
// Self-service (/me) needs only a user linked to an employee.
$all = ['talent.view', 'talent.recruit', 'talent.interview', 'talent.manage', 'talent.requests', 'talent.payroll_review', 'talent.privacy'];

return [
    'permissions' => $all,
    'roles' => [
        'hr-officer' => ['talent.view', 'talent.recruit', 'talent.interview', 'talent.manage', 'talent.requests', 'talent.privacy'],
        'payroll-officer' => ['talent.view', 'talent.payroll_review'],
        'finance-director' => ['talent.view', 'talent.payroll_review'],
        'front-office-manager' => ['talent.view', 'talent.interview'],
        'housekeeping-manager' => ['talent.view', 'talent.interview'],
        'fnb-manager' => ['talent.view', 'talent.interview'],
        'auditor' => ['talent.view'],
    ],
];

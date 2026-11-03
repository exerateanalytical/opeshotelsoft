<?php

// A-A core ledger.
// - accounting.budgets / budgets_approve: build budgets; approve (freeze) them.
// - accounting.dimensions: manage cost centres, outlets, projects and their posting defaults.
// - accounting.schedules: accruals, prepayments, recurring journals.
// - accounting.period_approve: decide period close/reopen requests (a different user than the requester).
// - accounting.intercompany: post inter-hotel transactions.
// - accounting.consolidate: group consolidated statements and group budget view.
// - accounting.audit: read the accounting audit trail.
return [
    'permissions' => ['accounting.budgets', 'accounting.budgets_approve', 'accounting.dimensions', 'accounting.schedules', 'accounting.period_approve', 'accounting.intercompany', 'accounting.consolidate', 'accounting.audit'],
    'roles' => [
        'finance-director' => ['accounting.budgets', 'accounting.budgets_approve', 'accounting.dimensions', 'accounting.schedules', 'accounting.period_approve', 'accounting.intercompany', 'accounting.consolidate', 'accounting.audit'],
        'accountant' => ['accounting.budgets', 'accounting.dimensions', 'accounting.schedules', 'accounting.intercompany', 'accounting.audit'],
        'auditor' => ['accounting.audit'],
        'hotel-owner' => ['accounting.consolidate', 'accounting.audit'],
    ],
];

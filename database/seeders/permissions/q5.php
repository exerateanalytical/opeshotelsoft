<?php

// Q5 Mobile Money everywhere.
// - payments.links: issue / cancel pay links and QR codes on folios, POS checks, invoices and bookings.
// - payments.reconcile: review queue of unmatched MoMo / statement receipts; post or ignore them.
// - payments.payouts: salary payout runs (MoMo bulk disbursement, bank file, confirmations).
return [
    'permissions' => ['payments.links', 'payments.reconcile', 'payments.payouts'],
    'roles' => [
        'finance-director' => ['payments.links', 'payments.reconcile', 'payments.payouts'],
        'accountant' => ['payments.links', 'payments.reconcile'],
        'front-office-manager' => ['payments.links', 'payments.reconcile'],
        'receptionist' => ['payments.links'],
        'night-auditor' => ['payments.links'],
        'reservations-agent' => ['payments.links'],
        'pos-cashier' => ['payments.links'],
        'fnb-manager' => ['payments.links'],
        'payroll-officer' => ['payments.payouts'],
    ],
];

<?php

// D-B money documents: invoices.credit issues a credit note (avoir). Printing a document needs the permission of its source
// (folios, cashier, pos, accounting, receivables, payables, reports); see MoneyController.
return [
    'permissions' => ['invoices.credit'],
    'roles' => [
        'finance-director' => ['invoices.credit'],
        'accountant' => ['invoices.credit'],
        'front-office-manager' => ['invoices.credit'],
    ],
];

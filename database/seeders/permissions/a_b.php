<?php

// A-B payables, tax, close.
// - payables.approve: decide approval steps of supplier invoices (role of the step or a delegation); create own delegations.
// - payables.approval_config: define approval chains by amount; delegate on behalf of others.
// - payables.capture: upload supplier invoice documents and confirm the extracted fields.
// - accounting.dunning / dunning_config: send customer reminder letters, hold dunning / configure levels, fees, interest.
// - accounting.bank_feed: import CSV/MT940/CAMT.053 statements and manage bank rules.
// - accounting.tax: view VAT returns, withholding, stamp duty; accounting.tax_file: file returns, set tax profile, remit withholding.
// - payroll.filings: export CNPS / IRPP / CFC-FNE-CAC declarations.
// - accounting.statements: SYSCOHADA statements pack; accounting.forecast: 13-week cash forecast.
// - accounting.close: view and sign month-end tasks; accounting.close_manage: templates, start a month, assign owners, sign for others.
return [
    'permissions' => ['payables.approve', 'payables.approval_config', 'payables.capture', 'accounting.dunning', 'accounting.dunning_config', 'accounting.bank_feed', 'accounting.tax',
        'accounting.tax_file', 'payroll.filings', 'accounting.statements', 'accounting.forecast', 'accounting.close', 'accounting.close_manage'],
    'roles' => [
        'finance-director' => ['payables.approve', 'payables.approval_config', 'payables.capture', 'accounting.dunning', 'accounting.dunning_config', 'accounting.bank_feed', 'accounting.tax',
            'accounting.tax_file', 'payroll.filings', 'accounting.statements', 'accounting.forecast', 'accounting.close', 'accounting.close_manage'],
        'accountant' => ['payables.approve', 'payables.capture', 'accounting.dunning', 'accounting.bank_feed', 'accounting.tax', 'accounting.statements', 'accounting.forecast', 'accounting.close', 'payroll.filings'],
        'payroll-officer' => ['payroll.filings', 'accounting.close'],
        'auditor' => ['accounting.tax', 'accounting.statements', 'accounting.forecast'],
        'hotel-owner' => ['accounting.statements', 'accounting.forecast'],
    ],
];

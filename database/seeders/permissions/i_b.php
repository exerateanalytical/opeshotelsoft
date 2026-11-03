<?php

// I-B inventory operations.
// - stockops.view: read RFQs, scan lookups, minibar, uniform/asset issues, menu engineering (also by the *.view pattern).
// - stockops.rfq: create RFQs, record quotes, award, convert to purchase orders.
// - stockops.scan: resolve scanned codes, receive against a PO by scan, register external barcodes.
// - stocktake.count: counter role, sees the blind sheet only. stocktake.review: sees expected quantities and variances. stocktake.approve: approves variances and posts the adjustment.
// - stockops.minibar: minibar pars, consumption posting to the folio, restock tasks.
// - stockops.issues: issue/return uniforms and assets to employees.
return [
    'permissions' => ['stockops.view', 'stockops.rfq', 'stockops.scan', 'stocktake.count', 'stocktake.review', 'stocktake.approve', 'stockops.minibar', 'stockops.issues'],
    'roles' => [
        'storekeeper' => ['stockops.view', 'stockops.rfq', 'stockops.scan', 'stocktake.count', 'stockops.minibar', 'stockops.issues'],
        'fnb-manager' => ['stockops.view', 'stockops.rfq', 'stocktake.review'],
        'housekeeping-manager' => ['stockops.view', 'stockops.minibar', 'stocktake.count'],
        'room-attendant' => ['stockops.minibar'],
        'finance-director' => ['stocktake.review', 'stocktake.approve'],
        'accountant' => ['stocktake.review'],
        'hr-officer' => ['stockops.view', 'stockops.issues'],
    ],
];

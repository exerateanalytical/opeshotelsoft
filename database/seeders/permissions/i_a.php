<?php

// I-A inventory control: recipes and costing, usage variance, par levels and reorder, lots and expiry, valuation.
// - stockcontrol.view: read recipes, variance, reorder suggestions, lots (also granted by the *.view pattern).
// - stockcontrol.manage: edit recipes, units, par levels, lead times, lot control; convert suggestions to draft POs.
// - stockcontrol.writeoff: write off expired or damaged lots (posts wastage to the ledger).
// - stockcontrol.valuation: valuation report as of a date.
return [
    'permissions' => ['stockcontrol.view', 'stockcontrol.manage', 'stockcontrol.writeoff', 'stockcontrol.valuation'],
    'roles' => [
        'storekeeper' => ['stockcontrol.view', 'stockcontrol.manage', 'stockcontrol.writeoff'],
        'fnb-manager' => ['stockcontrol.view', 'stockcontrol.manage'],
        'finance-director' => ['stockcontrol.view', 'stockcontrol.valuation'],
        'accountant' => ['stockcontrol.view', 'stockcontrol.valuation'],
        'auditor' => ['stockcontrol.valuation'],
        'hotel-owner' => ['stockcontrol.valuation'],
    ],
];

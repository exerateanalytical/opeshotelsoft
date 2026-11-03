<?php

// D-C operations and HR documents: printing a document needs the permission of its source module (reservations, frontdesk, housekeeping,
// pos, stock, purchasing, hr, payroll, people.discipline, people.exit); see OpsController. documents.ops.regenerate re-issues an HR letter
// (the previous verify page then says "replaced by ...").
return [
    'permissions' => ['documents.ops.regenerate'],
    'roles' => [
        'hr-officer' => ['documents.ops.regenerate'],
    ],
];

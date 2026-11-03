<?php

// D-A document engine: documents.verify.log reads the issued-documents register and its reprint log.
return [
    'permissions' => ['documents.verify.log'],
    'roles' => [
        'hotel-owner' => ['documents.verify.log'],
        'auditor' => ['documents.verify.log'],
    ],
];

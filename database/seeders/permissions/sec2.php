<?php

// SEC2 security fixes: manual loyalty adjustments above the programme cap (default 5,000 points) need this permission.
// Least privilege: no role gets it by default; group-owner and general-manager hold every permission ('*').
return [
    'permissions' => ['loyalty.adjust_large'],
    'roles' => [],
];

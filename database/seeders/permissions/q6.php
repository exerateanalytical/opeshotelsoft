<?php

// Q6 WhatsApp guest journey and staff inbox.
// - messaging.view: read conversations, templates, quick replies.
// - messaging.reply: reply, send templates/media, assign, close.
// - messaging.manage: template registry (Meta status), quick replies, number mapping.
return [
    'permissions' => ['messaging.view', 'messaging.reply', 'messaging.manage'],
    'roles' => [
        'front-office-manager' => ['messaging.view', 'messaging.reply', 'messaging.manage'],
        'receptionist' => ['messaging.view', 'messaging.reply'],
        'reservations-agent' => ['messaging.view', 'messaging.reply'],
    ],
];

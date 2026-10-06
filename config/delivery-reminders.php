<?php

return [
    /*
    | Default off so enabling the schedule does not email the historic overdue book.
    | Run `php artisan delivery-reminders:mark-seen` before flipping this on.
    */
    'enabled' => (bool) env('DELIVERY_REMINDERS_ENABLED', false),

    /*
    | Overdue candidates whose delivery date is older than this many days are ignored
    | so a first run cannot spray the entire overdue backlog.
    */
    'overdue_max_days_past' => (int) env('DELIVERY_REMINDERS_OVERDUE_MAX_DAYS_PAST', 14),

    /*
    | Shared sales mailbox per state entity. Send and reply-poll use this address.
    | Aliases such as SRCQLD / SRCVIC / SRCVIV resolve to the same mailbox as RCP*.
    | Override via env when a state uses a different shared inbox.
    */
    'mailboxes' => [
        'RCPQLD' => env('DELIVERY_REMINDERS_MAILBOX_QLD', 'salesqld@rcpa.com.au'),
        'SRCQLD' => env('DELIVERY_REMINDERS_MAILBOX_QLD', 'salesqld@rcpa.com.au'),
        'RCPNSW' => env('DELIVERY_REMINDERS_MAILBOX_NSW', 'salesnsw@rcpa.com.au'),
        'SRCNSW' => env('DELIVERY_REMINDERS_MAILBOX_NSW', 'salesnsw@rcpa.com.au'),
        'RCPVIC' => env('DELIVERY_REMINDERS_MAILBOX_VIC', 'vicsales@rcpa.com.au'),
        'SRCVIC' => env('DELIVERY_REMINDERS_MAILBOX_VIC', 'vicsales@rcpa.com.au'),
        'SRCVIV' => env('DELIVERY_REMINDERS_MAILBOX_VIC', 'vicsales@rcpa.com.au'),
        'RCPSA' => env('DELIVERY_REMINDERS_MAILBOX_SA', 'salessa@rcpa.com.au'),
        'SRCSA' => env('DELIVERY_REMINDERS_MAILBOX_SA', 'salessa@rcpa.com.au'),
    ],

    'graph' => [
        'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
        'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
        'login_base_url' => env('MICROSOFT_GRAPH_LOGIN_BASE_URL', 'https://login.microsoftonline.com'),
        'graph_base_url' => env('MICROSOFT_GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
    ],
];

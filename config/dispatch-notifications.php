<?php

return [
    'default' => env('DISPATCH_NOTIFICATION_DRIVER', 'microsoft-graph'),

    'graph' => [
        'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
        'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
        'sender' => env('MICROSOFT_GRAPH_SENDER'),
        'login_base_url' => env('MICROSOFT_GRAPH_LOGIN_BASE_URL', 'https://login.microsoftonline.com'),
        'graph_base_url' => env('MICROSOFT_GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
        'expected_application_permission' => 'Mail.Send',
    ],
];

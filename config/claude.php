<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude API
    |--------------------------------------------------------------------------
    |
    | Used to write the plain-English summary on a plan run. Advisory only — the
    | narrator never writes a quantity, so the app works with this unset.
    |
    | No Anthropic PHP SDK is installed, so the Messages API is called over HTTP
    | with Laravel's client. Adding the SDK is a dependency change.
    |
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

    'version' => '2023-06-01',

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

    // Summarising a small metrics block is not hard work, so it does not need
    // the default effort. Raise it if the narratives read thin.
    'effort' => env('ANTHROPIC_EFFORT', 'medium'),

    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2000),

    'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),

];

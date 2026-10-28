<?php

return [

    /*
    | Production schedule statuses that commit stock to the logistics engine.
    */
    'production_commit_statuses' => [
        'scheduled',
        'in_progress',
    ],

    'default_load_capacity_tonnes' => 24,

    'schedule_horizon_days' => 14,

    /*
    | Carrier emails include only confirmed lines. The internal copy still includes every line.
    */
    'require_confirmed_for_carrier_email' => true,

    'default_minimum_billable_tonnes' => [
        'semi' => 20,
        'rigid' => 10,
        'other' => 0,
    ],

    'internal_schedule_recipients' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('LOGISTICS_INTERNAL_SCHEDULE_RECIPIENTS', '')),
    ))),

];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Orbit Mode
    |--------------------------------------------------------------------------
    |
    | Determines whether Orbit is running in web (single node) or
    | desktop (multi-node) mode.
    |
    */
    'mode' => env('ORBIT_MODE', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Multi-Node Management
    |--------------------------------------------------------------------------
    |
    | When true, enables multi-node management UI and routing.
    | When false, uses implicit node injection via middleware.
    |
    */
    'multi_node' => env('MULTI_NODE_MANAGEMENT', false),

    /*
    |--------------------------------------------------------------------------
    | Database Path
    |--------------------------------------------------------------------------
    |
    | The path to the SQLite database file when running in CLI mode.
    |
    */
    'database' => ['path' => env('ORBIT_DATABASE_PATH')],
];

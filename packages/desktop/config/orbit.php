<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Orbit Mode
    |--------------------------------------------------------------------------
    |
    | Desktop mode enables multi-node management with prefixed routes.
    | Web mode uses a single implicit node with flat routes.
    |
    */
    'mode' => env('ORBIT_MODE', 'desktop'),

    /*
    |--------------------------------------------------------------------------
    | Multi-Node Management
    |--------------------------------------------------------------------------
    |
    | When true, enables multi-node management UI and routing.
    | This is the default for orbit-desktop (NativePHP app).
    |
    */
    'multi_node' => env('MULTI_NODE_MANAGEMENT', true),

    'api_url' => env('ORBIT_API_URL'),
];

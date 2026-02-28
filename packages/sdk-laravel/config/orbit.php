<?php

return [
    'base_url' => env('ORBIT_BASE_URL', 'http://localhost'),

    'gateway_url' => env('ORBIT_GATEWAY_URL'),

    'timeout' => env('ORBIT_TIMEOUT', 30),

    'verify_ssl' => env('ORBIT_VERIFY_SSL', false),
];

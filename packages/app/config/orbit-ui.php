<?php

declare(strict_types=1);

// config for HardImpact\Orbit\App
return [
    /*
    |--------------------------------------------------------------------------
    | Default PHP Versions
    |--------------------------------------------------------------------------
    |
    | Default PHP versions to use when provisioning nodes.
    | These are used as fallback when node-specific versions
    | cannot be retrieved.
    |
    */
    'php' => [
        'versions' => \HardImpact\Orbit\Core\Support\PhpVersion::SUPPORTED,
        'default' => \HardImpact\Orbit\Core\Support\PhpVersion::DEFAULT,
        'recommended' => \HardImpact\Orbit\Core\Support\PhpVersion::SUPPORTED[0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Ports
    |--------------------------------------------------------------------------
    |
    | Default ports for various services.
    |
    */
    'ports' => [
        'ssh' => 22,
        'reverb' => 443,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provisioning Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for node provisioning process.
    |
    */
    'provisioning' => [
        'mac_setup_steps' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Configuration
    |--------------------------------------------------------------------------
    |
    | Default path prefixes and patterns.
    |
    */
    'paths' => [
        'home_prefix' => '/home/',
    ],
];

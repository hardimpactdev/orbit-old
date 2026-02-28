<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Tests;

use HardImpact\Orbit\OrbitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            OrbitServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('orbit.base_url', 'https://orbit.test');
        config()->set('orbit.gateway_url', 'https://orbit.test/mcp/gateway');
    }
}

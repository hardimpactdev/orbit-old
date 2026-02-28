<?php

declare(strict_types=1);

namespace HardImpact\Orbit;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OrbitServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('orbit')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Orbit::class, fn (): Orbit => new Orbit(
            baseUrl: config('orbit.base_url'),
            gatewayUrl: config('orbit.gateway_url'),
            timeout: (int) config('orbit.timeout', 30),
            verifySsl: (bool) config('orbit.verify_ssl', false),
        ));
    }
}

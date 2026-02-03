<?php

use App\Enums\ExitCode;
use App\Services\CaddyManager;
use App\Services\PhpManager;
use App\Services\ServiceManager;

beforeEach(function () {
    $this->serviceManager = Mockery::mock(ServiceManager::class);
    $this->phpManager = Mockery::mock(PhpManager::class);
    $this->caddyManager = Mockery::mock(CaddyManager::class);
    $this->app->instance(ServiceManager::class, $this->serviceManager);
    $this->app->instance(PhpManager::class, $this->phpManager);
    $this->app->instance(CaddyManager::class, $this->caddyManager);
});

it('stops all services successfully', function () {
    // No FPM sockets - host PHP-FPM not detected
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    $this->serviceManager->shouldReceive('stopAll')->once()->andReturn(true);

    $this->artisan('stop')
        ->expectsOutputToContain('Orbit stopped')
        ->assertExitCode(0);
});

it('reports failure when a service fails to stop', function () {
    // No FPM sockets - host PHP-FPM not detected
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    $this->serviceManager->shouldReceive('stopAll')->once()->andReturn(false);

    $this->artisan('stop')
        ->expectsOutputToContain('Orbit stopped')
        ->assertExitCode(ExitCode::ServiceFailed->value);
});

it('outputs json when --json flag is used', function () {
    // No FPM sockets - host PHP-FPM not detected
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    $this->serviceManager->shouldReceive('stopAll')->once()->andReturn(true);

    $this->artisan('stop --json')
        ->assertExitCode(0);
});

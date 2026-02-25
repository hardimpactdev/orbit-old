<?php

use App\Services\ConfigManager;
use App\Services\ProjectScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->projectScanner = Mockery::mock(ProjectScanner::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(ProjectScanner::class, $this->projectScanner);
});

it('lists all projects', function () {
    $this->projectScanner->shouldReceive('scan')->andReturn([
        ['name' => 'myproject', 'domain' => 'myproject.test', 'path' => '/path/to/myproject', 'php_version' => '8.3', 'has_custom_php' => false, 'has_public_folder' => true],
        ['name' => 'another', 'domain' => 'another.test', 'path' => '/path/to/another', 'php_version' => '8.4', 'has_custom_php' => true, 'has_public_folder' => true],
    ]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('project:list')
        ->expectsOutputToContain('myproject.test')
        ->expectsOutputToContain('another.test')
        ->assertExitCode(0);
});

it('shows warning when no projects found', function () {
    $this->projectScanner->shouldReceive('scan')->andReturn([]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('project:list')
        ->expectsOutputToContain('No projects found')
        ->assertExitCode(0);
});

it('outputs json when --json flag is used', function () {
    $this->projectScanner->shouldReceive('scan')->andReturn([
        ['name' => 'myproject', 'domain' => 'myproject.test', 'path' => '/path/to/myproject', 'php_version' => '8.3', 'has_custom_php' => false, 'has_public_folder' => true],
    ]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('project:list --json')
        ->assertExitCode(0);
});

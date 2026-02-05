<?php

declare(strict_types=1);

use App\Commands\Service\ServicePhpCommand;
use App\Services\ServiceManager;

beforeEach(function () {
    $this->serviceManager = app(ServiceManager::class);
});

it('has service:php command', function () {
    $this->artisan('service:php')
        ->assertSuccessful();
});

it('shows status by default', function () {
    $this->artisan('service:php')
        ->expectsOutputToContain('PHP Memory Limits')
        ->assertSuccessful();
});

it('accepts set-memory-limit action', function () {
    $this->artisan('service:php', ['action' => 'set-memory-limit', 'value' => '256M'])
        ->expectsOutputToContain('PHP memory limit set to: 256M')
        ->assertSuccessful();
});

it('accepts memory limit value as argument', function () {
    $this->artisan('service:php', ['action' => 'set-memory-limit', 'value' => '512M'])
        ->expectsOutputToContain('512M')
        ->assertSuccessful();
});

it('validates memory limit format', function () {
    $this->artisan('service:php', ['action' => 'set-memory-limit', 'value' => 'invalid'])
        ->expectsOutputToContain('Invalid memory limit format')
        ->assertFailed();
});

it('accepts -1 for unlimited memory', function () {
    $this->artisan('service:php', ['action' => 'set-memory-limit', 'value' => '-1'])
        ->expectsOutputToContain('PHP memory limit set to: -1')
        ->assertSuccessful();
});

it('shows error for unknown action', function () {
    $this->artisan('service:php', ['action' => 'unknown-action'])
        ->expectsOutputToContain('Unknown action')
        ->assertFailed();
});

it('has correct command signature', function () {
    $command = app(ServicePhpCommand::class);

    expect($command->getName())->toBe('service:php');
    expect($command->getDescription())->toBe('Manage PHP configuration');
});

it('signature accepts action and value arguments', function () {
    $command = new ServicePhpCommand;
    $definition = $command->getDefinition();

    expect($definition->hasArgument('action'))->toBeTrue();
    expect($definition->hasArgument('value'))->toBeTrue();
});

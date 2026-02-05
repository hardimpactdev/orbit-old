<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\GatewayManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;

/**
 * Set up Orbit - interactive wizard when run without arguments.
 *
 * When run as `orbit setup` (no args): Shows interactive wizard
 * When run with args: Delegates to legacy setup or install command
 */
final class SetupCommand extends Command
{
    protected $signature = 'setup
        {--tld=test : TLD for local development sites}
        {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--json : Output progress as JSON for programmatic consumption}';

    protected $description = 'Set up Orbit (interactive wizard or legacy mode with options)';

    public function handle(GatewayManager $gatewayManager): int
    {
        // Check if any non-default options were provided
        if ($this->hasCustomOptions()) {
            // Run legacy setup mode
            return $this->runLegacySetup();
        }

        // Run interactive wizard
        return $this->runWizard($gatewayManager);
    }

    /**
     * Check if user provided custom options.
     */
    private function hasCustomOptions(): bool
    {
        // Check if any option was explicitly set to a non-default value
        $tld = $this->option('tld');
        $phpVersions = $this->option('php-versions');

        // If tld is not the default 'test', or php-versions is not the default
        if ($tld !== 'test') {
            return true;
        }

        if ($phpVersions !== '8.4,8.5') {
            return true;
        }

        if ($this->option('skip-docker') !== false) {
            return true;
        }

        if ($this->option('json') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Run the interactive setup wizard.
     */
    private function runWizard(GatewayManager $gatewayManager): int
    {
        $this->info('🚀 Orbit Setup');
        $this->newLine();

        $setupType = select(
            label: 'Where would you like to set up Orbit?',
            options: [
                'local' => '🖥️  This machine (local development)',
                'remote' => '🌐 Remote gateway server',
            ],
            default: 'local',
        );

        if ($setupType === 'local') {
            $this->newLine();

            return $this->call('install');
        }

        return $this->setupRemote($gatewayManager);
    }

    /**
     * Set up Orbit on a remote gateway.
     */
    private function setupRemote(GatewayManager $gatewayManager): int
    {
        $this->newLine();

        // If no gateways exist, go straight to setting up a new one
        if (! $gatewayManager->hasAny()) {
            return $this->call('setup:gateway');
        }

        // Show available gateways
        $options = $gatewayManager->getOptions();
        $options['new'] = '➕ Set up a new gateway server';

        $gatewayId = select(
            label: 'Select a gateway server',
            options: $options,
        );

        if ($gatewayId === 'new') {
            $this->newLine();

            return $this->call('setup:gateway');
        }

        $gateway = $gatewayManager->get($gatewayId);
        if ($gateway === null) {
            $this->error('Gateway not found.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Selected gateway: {$gateway['name']}");
        $this->line("  IP: {$gateway['ip']}");
        $this->newLine();

        // Ask what to do with this gateway
        $action = select(
            label: 'What would you like to do?',
            options: [
                'setup' => '🔧 Set up this gateway server (install Orbit)',
                'connect' => '🔗 Connect this machine to the gateway (as VPN client)',
            ],
            default: 'setup',
        );

        if ($action === 'setup') {
            $this->newLine();

            return $this->call('setup:gateway', [
                'ip' => $gateway['ip'],
            ]);
        }

        // Show connection instructions
        $this->newLine();
        $this->info('To connect to this gateway:');
        $this->line("  <fg=cyan>ssh orbit@{$gateway['ip']}</>");
        $this->newLine();
        $this->info('Once connected, set up this machine as a client:');
        $this->line('  1. Create a VPN client: <fg=cyan>orbit gateway:make:client</>');
        $this->line('  2. Download the config from the gateway web UI');
        $this->line('  3. Import into your WireGuard client');

        return self::SUCCESS;
    }

    /**
     * Run legacy setup mode (delegates to install command).
     */
    private function runLegacySetup(): int
    {
        $this->warn('⚠️  Using legacy setup mode. Consider using `orbit install` instead.');
        $this->newLine();

        // Forward to install command with the provided options
        $options = [];

        if ($this->option('tld') !== 'test') {
            $options['--tld'] = $this->option('tld');
        }

        if ($this->option('php-versions') !== '8.4,8.5') {
            $options['--php-versions'] = $this->option('php-versions');
        }

        if ($this->option('skip-docker')) {
            $options['--skip-docker'] = true;
        }

        if ($this->option('json')) {
            $options['--json'] = true;
        }

        return $this->call('install', $options);
    }
}

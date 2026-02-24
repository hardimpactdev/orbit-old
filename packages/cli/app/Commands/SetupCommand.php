<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TemplateRegistry;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

final class SetupCommand extends Command
{
    protected $signature = 'setup
        {--tld=test : TLD for local development sites}
        {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--json : Output progress as JSON for programmatic consumption}';

    protected $description = 'Set up Orbit (interactive wizard or legacy mode with options)';

    public function handle(GatewayManager $gatewayManager, TemplateRegistry $registry): int
    {
        if ($this->hasCustomOptions()) {
            return $this->runLegacySetup();
        }

        return $this->runWizard($gatewayManager, $registry);
    }

    private function hasCustomOptions(): bool
    {
        $tld = $this->option('tld');
        $phpVersions = $this->option('php-versions');

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

    private function runWizard(GatewayManager $gatewayManager, TemplateRegistry $registry): int
    {
        $this->info('🚀 Orbit Setup');
        $this->newLine();

        $setupType = select(
            label: 'Where would you like to set up Orbit?',
            options: [
                'local' => '🖥️  This machine',
                'remote' => '🌐 Remote machine',
            ],
            default: 'local',
        );

        $this->newLine();

        if ($setupType === 'remote') {
            return $this->setupRemote($gatewayManager, $registry);
        }

        return $this->setupLocal($registry);
    }

    private function setupLocal(TemplateRegistry $registry): int
    {
        $available = $registry->forPlatform(PHP_OS_FAMILY);
        $choices = [];
        foreach ($available as $t) {
            $choices[$t->name()] = "{$t->label()} - {$t->description()}";
        }

        $templateName = select(
            label: 'Select an installation template',
            options: $choices,
            default: 'php-dev',
        );

        $installArgs = ['--template' => $templateName];

        if ($templateName === 'php-production') {
            $services = multiselect(
                label: 'Which Docker services do you need?',
                options: [
                    'postgres' => 'PostgreSQL',
                    'redis' => 'Redis',
                    'mailpit' => 'Mailpit',
                    'reverb' => 'Reverb',
                ],
                hint: 'Leave empty for no Docker services',
            );

            if ($services !== []) {
                $installArgs['--services'] = implode(',', $services);
            }
        }

        if (in_array($templateName, ['php-dev', 'php-production'], true)) {
            $nodePackages = multiselect(
                label: 'Which Node package managers would you like to install?',
                options: [
                    'bun' => 'Bun',
                    'npm' => 'NPM (includes Node)',
                    'yarn' => 'Yarn (requires NPM)',
                    'pnpm' => 'pnpm (requires NPM)',
                ],
                hint: 'Leave empty for none',
            );

            if ($nodePackages !== []) {
                $installArgs['--node-packages'] = implode(',', $nodePackages);
            }
        }

        $this->newLine();

        return $this->call('install', $installArgs);
    }

    private function setupRemote(GatewayManager $gatewayManager, TemplateRegistry $registry): int
    {
        $available = $registry->forPlatform('Linux');
        $choices = [];
        foreach ($available as $t) {
            $choices[$t->name()] = "{$t->label()} - {$t->description()}";
        }

        $templateName = select(
            label: 'What do you want to install on the remote machine?',
            options: $choices,
            default: 'php-dev',
        );

        if ($templateName === 'gateway') {
            return $this->setupGateway($gatewayManager);
        }

        $remoteArgs = ['--template' => $templateName];

        if ($templateName === 'client') {
            $gatewayId = $this->selectGatewayForClient($gatewayManager);
            if ($gatewayId !== null) {
                $remoteArgs['--gateway'] = $gatewayId;
            }
        }

        if ($templateName === 'php-production') {
            $services = multiselect(
                label: 'Which Docker services do you need?',
                options: [
                    'postgres' => 'PostgreSQL',
                    'redis' => 'Redis',
                    'mailpit' => 'Mailpit',
                    'reverb' => 'Reverb',
                ],
                hint: 'Leave empty for no Docker services',
            );

            if ($services !== []) {
                $remoteArgs['--services'] = implode(',', $services);
            }
        }

        if (in_array($templateName, ['php-dev', 'php-production'], true)) {
            $nodePackages = multiselect(
                label: 'Which Node package managers would you like to install?',
                options: [
                    'bun' => 'Bun',
                    'npm' => 'NPM (includes Node)',
                    'yarn' => 'Yarn (requires NPM)',
                    'pnpm' => 'pnpm (requires NPM)',
                ],
                hint: 'Leave empty for none',
            );

            if ($nodePackages !== []) {
                $remoteArgs['--node-packages'] = implode(',', $nodePackages);
            }
        }

        $this->newLine();

        return $this->call('setup:remote', $remoteArgs);
    }

    private function setupGateway(GatewayManager $gatewayManager): int
    {
        $this->newLine();

        if (! $gatewayManager->hasAny()) {
            return $this->call('setup:gateway');
        }

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
        $this->info("Selected gateway: {$gateway->name}");
        $this->line("  IP: {$gateway->ip_address}");
        $this->newLine();

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
                'ip' => $gateway->ip_address,
            ]);
        }

        $this->newLine();
        $this->info('To connect to this gateway:');
        $this->line("  <fg=cyan>ssh orbit@{$gateway->ip_address}</>");
        $this->newLine();
        $this->info('Once connected, set up this machine as a client:');
        $this->line('  1. Create a VPN client: <fg=cyan>orbit gateway:make:client</>');
        $this->line('  2. Download the config from the gateway web UI');
        $this->line('  3. Import into your WireGuard client');

        return self::SUCCESS;
    }

    private function selectGatewayForClient(GatewayManager $gatewayManager): ?int
    {
        if (! $gatewayManager->hasAny()) {
            $this->newLine();
            $this->warn('⚠️  No gateways configured. Client node will not have VPN access.');
            $this->line('   You can set up a gateway later with: <fg=cyan>orbit setup</> → Remote → Gateway');
            $this->newLine();

            return null;
        }

        $this->newLine();

        $options = $gatewayManager->getOptions();
        $options['skip'] = 'Skip VPN registration';

        $gatewayId = select(
            label: 'Which gateway should this client connect to?',
            options: $options,
        );

        if ($gatewayId === 'skip') {
            return null;
        }

        return (int) $gatewayId;
    }

    private function runLegacySetup(): int
    {
        $this->warn('⚠️  Using legacy setup mode. Consider using `orbit install` instead.');
        $this->newLine();

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

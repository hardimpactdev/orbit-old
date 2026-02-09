<?php

declare(strict_types=1);

namespace App\Templates;

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Components\CaddyComponent;
use App\Components\DockerComponent;
use App\Components\GatewayDnsComponent;
use App\Components\PhpComponent;
use App\Components\VpnComponent;
use App\Contracts\Component;
use App\Contracts\Template;
use App\Data\Install\InstallContext;

/**
 * Gateway template - Central hub for Orbit machines to communicate.
 *
 * Features:
 * - Docker for running standalone services
 * - WG Easy (WireGuard VPN) for secure machine-to-machine communication
 * - DNS server (dnsmasq) for routing custom TLDs to VPN clients
 */
final readonly class GatewayTemplate implements Template
{
    public function __construct(
        private PhpComponent $php,
        private CaddyComponent $caddy,
        private DockerComponent $docker,
        private VpnComponent $vpn,
        private GatewayDnsComponent $dns,
    ) {}

    public function name(): string
    {
        return 'gateway';
    }

    public function label(): string
    {
        return 'Gateway';
    }

    public function description(): string
    {
        return 'Central hub with PHP, Caddy, Horizon, VPN, and DNS for orchestrating client nodes';
    }

    public function platforms(): array
    {
        return ['Darwin', 'Linux'];
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, $this->platforms(), true);
    }

    public function installSteps(string $osFamily, ?InstallContext $context = null): array
    {
        return match ($osFamily) {
            'Darwin' => $this->macSteps(),
            'Linux' => $this->linuxSteps(),
            default => throw new \InvalidArgumentException("Unsupported platform: {$osFamily}"),
        };
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    private function macSteps(): array
    {
        return [
            ['action' => Mac\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Mac\InstallOrbStack::class, 'name' => 'Installing OrbStack'],
            ['action' => Mac\InstallSupportTools::class, 'name' => 'Installing support tools'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],

            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            ['action' => Mac\ConfigureDns::class, 'name' => 'Configuring DNS'],

            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],

            // Gateway-specific steps
            ['action' => Shared\InstallWgEasy::class, 'name' => 'Installing WG Easy VPN'],
            ['action' => Shared\ConfigureGatewayDns::class, 'name' => 'Configuring gateway DNS'],

            ['action' => Shared\GatewayHealthCheck::class, 'name' => 'Running health checks'],
        ];
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    private function linuxSteps(): array
    {
        return [
            ['action' => Linux\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
            ['action' => Linux\InstallPhp::class, 'name' => 'Installing PHP'],
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Linux\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Linux\InstallHorizon::class, 'name' => 'Installing Horizon'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],
            ['action' => Shared\InitializeNode::class, 'name' => 'Initializing node'],

            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            ['action' => Linux\ConfigureDns::class, 'name' => 'Configuring DNS'],

            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],

            // Gateway-specific steps
            ['action' => Shared\InstallWgEasy::class, 'name' => 'Installing WG Easy VPN'],
            ['action' => Shared\ConfigureGatewayDns::class, 'name' => 'Configuring gateway DNS'],

            ['action' => Shared\GatewayHealthCheck::class, 'name' => 'Running health checks'],
        ];
    }

    /**
     * @return array<Component>
     */
    public function components(string $osFamily, ?InstallContext $context = null): array
    {
        return array_filter(
            [$this->php, $this->caddy, $this->docker, $this->vpn, $this->dns],
            fn (Component $c) => $c->supportsPlatform($osFamily),
        );
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function prepareSteps(string $osFamily, ?InstallContext $context = null): array
    {
        $steps = [];

        foreach ($this->components($osFamily) as $component) {
            $prepareClass = $component->prepare($osFamily);

            if ($prepareClass === null) {
                continue;
            }

            $steps[] = [
                'action' => $prepareClass,
                'name' => "Checking {$component->label()}",
            ];
        }

        return $steps;
    }
}

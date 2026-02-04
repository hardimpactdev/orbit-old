<?php

declare(strict_types=1);

namespace App\Templates;

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Components\CaddyComponent;
use App\Components\DnsComponent;
use App\Components\DockerComponent;
use App\Components\PhpComponent;
use App\Contracts\Component;
use App\Contracts\Template;

final readonly class DevelopmentTemplate implements Template
{
    public function __construct(
        private DockerComponent $docker,
        private PhpComponent $php,
        private CaddyComponent $caddy,
        private DnsComponent $dns,
    ) {}

    public function name(): string
    {
        return 'development';
    }

    public function label(): string
    {
        return 'Development';
    }

    public function description(): string
    {
        return 'Full local development environment with PHP-FPM, Caddy, Docker services, and DNS';
    }

    public function platforms(): array
    {
        return ['Darwin', 'Linux'];
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, $this->platforms(), true);
    }

    public function installSteps(string $osFamily): array
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
            ['action' => Mac\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Mac\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Mac\InstallSupportTools::class, 'name' => 'Installing support tools'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],

            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Mac\ConfigureDns::class, 'name' => 'Configuring DNS'],

            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],

            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Mac\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],

            ['action' => Shared\HealthCheck::class, 'name' => 'Running health checks'],
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
            ['action' => Linux\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Linux\InstallSupportTools::class, 'name' => 'Installing support tools'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],

            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Linux\ConfigureDns::class, 'name' => 'Configuring DNS'],

            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],

            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Linux\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],

            ['action' => Shared\HealthCheck::class, 'name' => 'Running health checks'],
        ];
    }

    /**
     * @return array<Component>
     */
    public function components(string $osFamily): array
    {
        return array_filter(
            [$this->docker, $this->php, $this->caddy, $this->dns],
            fn (Component $c) => $c->supportsPlatform($osFamily),
        );
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function prepareSteps(string $osFamily): array
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

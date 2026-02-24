<?php

declare(strict_types=1);

namespace App\Templates;

use App\Actions\Install\Brew;
use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Components\CaddyComponent;
use App\Components\DockerComponent;
use App\Components\PhpComponent;
use App\Contracts\Component;
use App\Contracts\Template;
use App\Data\Install\InstallContext;

final readonly class PhpProductionTemplate implements Template
{
    public function __construct(
        private DockerComponent $docker,
        private PhpComponent $php,
        private CaddyComponent $caddy,
    ) {}

    public function name(): string
    {
        return 'php-production';
    }

    public function label(): string
    {
        return 'PHP Production';
    }

    public function description(): string
    {
        return 'PHP-FPM and Caddy web server. Optionally add Docker services.';
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
            'Darwin' => $this->macSteps($context),
            'Linux' => $this->linuxSteps($context),
            default => throw new \InvalidArgumentException("Unsupported platform: {$osFamily}"),
        };
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    private function macSteps(?InstallContext $context): array
    {
        $steps = [
            ['action' => Mac\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Brew\InstallPhp::class, 'name' => 'Installing PHP'],
            ['action' => Mac\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Brew\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Mac\InstallSupportTools::class, 'name' => 'Installing support tools'],
            ['action' => Brew\InstallNodePackageManagers::class, 'name' => 'Installing node package managers'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],
            ['action' => Shared\InitializeNode::class, 'name' => 'Initializing node'],

            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Mac\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],
        ];

        if ($context?->needsDocker()) {
            $steps = [
                ...$steps,
                ['action' => Mac\InstallOrbStack::class, 'name' => 'Installing OrbStack'],
                ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],
                ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
                ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],
                ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ];
        }

        $steps[] = ['action' => Shared\HealthCheck::class, 'name' => 'Running health checks'];

        return $steps;
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    private function linuxSteps(?InstallContext $context): array
    {
        $steps = [
            ['action' => Linux\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Linux\InstallPhp::class, 'name' => 'Installing PHP'],
            ['action' => Linux\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Linux\InstallSupportTools::class, 'name' => 'Installing support tools'],
            ['action' => Linux\InstallNodePackageManagers::class, 'name' => 'Installing node package managers'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],
            ['action' => Shared\InitializeNode::class, 'name' => 'Initializing node'],

            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Linux\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],

            ['action' => Linux\InstallFail2ban::class, 'name' => 'Installing fail2ban'],
            ['action' => Linux\ConfigureUnattendedUpgrades::class, 'name' => 'Configuring security updates'],
        ];

        if ($context?->needsDocker()) {
            $steps = [
                ...$steps,
                ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
                ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],
                ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
                ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],
                ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ];
        }

        $steps[] = ['action' => Shared\HealthCheck::class, 'name' => 'Running health checks'];

        return $steps;
    }

    /**
     * @return array<Component>
     */
    public function components(string $osFamily, ?InstallContext $context = null): array
    {
        $components = [$this->php, $this->caddy];

        if ($context?->needsDocker()) {
            $components[] = $this->docker;
        }

        return array_filter(
            $components,
            fn (Component $c) => $c->supportsPlatform($osFamily),
        );
    }

    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function prepareSteps(string $osFamily, ?InstallContext $context = null): array
    {
        $steps = [];

        foreach ($this->components($osFamily, $context) as $component) {
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

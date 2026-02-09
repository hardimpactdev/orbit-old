<?php

declare(strict_types=1);

namespace App\Templates;

use App\Actions\Install\Linux;
use App\Actions\Install\Shared;
use App\Components\CaddyComponent;
use App\Components\DockerComponent;
use App\Components\PhpComponent;
use App\Contracts\Component;
use App\Contracts\Template;
use App\Data\Install\InstallContext;

/**
 * Client node template - Minimal spoke managed via SSH from gateway.
 *
 * Features:
 * - PHP-FPM on host for running Laravel projects
 * - Caddy web server on host
 * - Docker services (Postgres, Redis, Mailpit)
 * - NO Orbit CLI installation (managed remotely)
 * - NO Horizon (orchestrated from gateway)
 * - NO Bun/Composer (projects pre-built)
 */
final readonly class ClientNodeTemplate implements Template
{
    public function __construct(
        private PhpComponent $php,
        private CaddyComponent $caddy,
        private DockerComponent $docker,
    ) {}

    public function name(): string
    {
        return 'client';
    }

    public function label(): string
    {
        return 'Client Node';
    }

    public function description(): string
    {
        return 'Minimal node with PHP-FPM, Caddy, and Docker services (no CLI)';
    }

    public function platforms(): array
    {
        return ['Linux'];
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return $osFamily === 'Linux';
    }

    public function installSteps(string $osFamily, ?InstallContext $context = null): array
    {
        if ($osFamily !== 'Linux') {
            throw new \InvalidArgumentException("Client nodes only support Linux, got: {$osFamily}");
        }

        return [
            ['action' => Linux\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
            ['action' => Linux\InstallPhp::class, 'name' => 'Installing PHP'],
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Linux\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],

            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\InitializeNode::class, 'name' => 'Initializing node'],

            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],

            ['action' => Shared\HealthCheck::class, 'name' => 'Running health checks'],
        ];
    }

    /**
     * @return array<Component>
     */
    public function components(string $osFamily, ?InstallContext $context = null): array
    {
        return array_filter(
            [$this->php, $this->caddy, $this->docker],
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

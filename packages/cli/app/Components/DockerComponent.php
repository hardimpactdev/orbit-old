<?php

declare(strict_types=1);

namespace App\Components;

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Actions\Prepare\Linux as PrepareLinux;
use App\Actions\Prepare\Mac as PrepareMac;
use App\Contracts\Component;

final readonly class DockerComponent implements Component
{
    public function name(): string
    {
        return 'docker';
    }

    public function label(): string
    {
        return 'Docker Runtime';
    }

    public function prepare(string $osFamily): ?string
    {
        return match ($osFamily) {
            'Darwin' => PrepareMac\PrepareDocker::class,
            'Linux' => PrepareLinux\PrepareDocker::class,
            default => null,
        };
    }

    public function installSteps(string $osFamily): array
    {
        return match ($osFamily) {
            'Darwin' => [
                ['action' => Mac\InstallOrbStack::class, 'name' => 'Installing OrbStack'],
                ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
                ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
                ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],
                ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],
                ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ],
            'Linux' => [
                ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
                ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
                ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
                ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],
                ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],
                ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ],
            default => [],
        };
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, ['Darwin', 'Linux'], true);
    }
}

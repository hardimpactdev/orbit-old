<?php

declare(strict_types=1);

namespace App\Components;

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Actions\Prepare\Linux as PrepareLinux;
use App\Actions\Prepare\Mac as PrepareMac;
use App\Contracts\Component;

final readonly class DnsComponent implements Component
{
    public function name(): string
    {
        return 'dns';
    }

    public function label(): string
    {
        return 'DNS Resolver';
    }

    public function prepare(string $osFamily): ?string
    {
        return match ($osFamily) {
            'Darwin' => PrepareMac\PrepareDns::class,
            'Linux' => PrepareLinux\PrepareDns::class,
            default => null,
        };
    }

    public function installSteps(string $osFamily): array
    {
        return match ($osFamily) {
            'Darwin' => [
                ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
                ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
                ['action' => Mac\ConfigureDns::class, 'name' => 'Configuring DNS'],
            ],
            'Linux' => [
                ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
                ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
                ['action' => Linux\ConfigureDns::class, 'name' => 'Configuring DNS'],
            ],
            default => [],
        };
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, ['Darwin', 'Linux'], true);
    }
}

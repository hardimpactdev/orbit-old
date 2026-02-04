<?php

declare(strict_types=1);

namespace App\Components;

use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Actions\Prepare\Linux as PrepareLinux;
use App\Actions\Prepare\Mac as PrepareMac;
use App\Contracts\Component;

final readonly class CaddyComponent implements Component
{
    public function name(): string
    {
        return 'caddy';
    }

    public function label(): string
    {
        return 'Caddy Web Server';
    }

    public function prepare(string $osFamily): ?string
    {
        return match ($osFamily) {
            'Darwin' => PrepareMac\PrepareCaddy::class,
            'Linux' => PrepareLinux\PrepareCaddy::class,
            default => null,
        };
    }

    public function installSteps(string $osFamily): array
    {
        // Use Homebrew for Caddy on both macOS and Linux
        return match ($osFamily) {
            'Darwin', 'Linux' => [
                ['action' => Mac\InstallCaddy::class, 'name' => 'Installing Caddy'],
                ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
                ['action' => Mac\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],
            ],
            default => [],
        };
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, ['Darwin', 'Linux'], true);
    }
}

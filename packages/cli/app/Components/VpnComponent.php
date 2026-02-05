<?php

declare(strict_types=1);

namespace App\Components;

use App\Actions\Prepare\Linux as PrepareLinux;
use App\Actions\Prepare\Mac as PrepareMac;
use App\Contracts\Component;

/**
 * VPN Component - Manages WG Easy (WireGuard) for machine-to-machine communication.
 */
final readonly class VpnComponent implements Component
{
    public function name(): string
    {
        return 'vpn';
    }

    public function label(): string
    {
        return 'VPN (WG Easy)';
    }

    public function prepare(string $osFamily): ?string
    {
        return match ($osFamily) {
            'Darwin' => PrepareMac\PrepareWgEasy::class,
            'Linux' => PrepareLinux\PrepareWgEasy::class,
            default => null,
        };
    }

    public function installSteps(string $osFamily): array
    {
        // WG Easy is installed via Docker, steps are handled by the template
        return [];
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, ['Darwin', 'Linux'], true);
    }
}

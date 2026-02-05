<?php

declare(strict_types=1);

namespace App\Components;

use App\Actions\Prepare\Linux as PrepareLinux;
use App\Actions\Prepare\Mac as PrepareMac;
use App\Contracts\Component;

/**
 * Gateway DNS Component - Manages DNS for VPN clients with custom TLD support.
 */
final readonly class GatewayDnsComponent implements Component
{
    public function name(): string
    {
        return 'gateway-dns';
    }

    public function label(): string
    {
        return 'Gateway DNS';
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
        // DNS steps are handled by the template
        return [];
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, ['Darwin', 'Linux'], true);
    }
}

<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class GatewayStatus extends Data
{
    public function __construct(
        public readonly array $node,
        public readonly int $gatewaysConfigured,
        public readonly int $dnsMappings,
        public readonly int $servicesRunning,
        public readonly int $servicesTotal,
        public readonly array $services,
    ) {}
}

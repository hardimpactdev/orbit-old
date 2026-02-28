<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class CloudflareZoneStatus extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $status,
        public readonly array $nameServers,
    ) {}
}

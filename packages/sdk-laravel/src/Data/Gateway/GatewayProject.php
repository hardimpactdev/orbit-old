<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class GatewayProject extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $githubRepo,
        public readonly ?string $productionDomain,
        public readonly ?string $cloudflareZoneId,
        public readonly ?string $cloudflareZoneName,
        public readonly ?int $activeDeployments = null,
        public readonly ?string $createdAt = null,
    ) {}
}

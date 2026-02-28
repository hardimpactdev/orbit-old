<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class Node extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $host,
        public readonly ?string $externalHost,
        public readonly string $environment,
        public readonly string $nodeType,
        public readonly string $status,
        public readonly bool $isActive,
        public readonly ?string $tld,
        public readonly int $deploymentsCount,
        public readonly ?string $user = null,
        public readonly ?int $port = null,
        public readonly ?string $vpnIp = null,
    ) {}
}

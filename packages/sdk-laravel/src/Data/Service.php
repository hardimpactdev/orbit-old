<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Represents a single service entry in the node status response.
 *
 * Services can be Docker containers, PHP-FPM pools, or host services (Caddy, Horizon).
 * The `type` field distinguishes between them: 'docker', 'php-fpm', 'host'.
 */
class Service extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $health,
        public readonly ?string $container,
        public readonly string $type,
    ) {}
}

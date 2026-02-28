<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Response from GET /api/status
 *
 * Represents the full orbit node status including services,
 * PHP versions, project count, and CLI metadata.
 */
class Status extends Data
{
    /**
     * @param  array<string, array{status: string, health: string|null, container: string|null, type: string}>  $services
     */
    public function __construct(
        public readonly bool $running,
        public readonly string $architecture,
        public readonly array $services,
        public readonly int $servicesRunning,
        public readonly int $servicesHealthy,
        public readonly int $servicesTotal,
        public readonly int $projectsCount,
        public readonly string $configPath,
        public readonly string $tld,
        public readonly string $defaultPhpVersion,
        public readonly ?string $cliVersion,
        public readonly ?string $cliPath,
    ) {}
}

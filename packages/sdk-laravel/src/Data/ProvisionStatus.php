<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Response from GET /api/projects/{slug}/provision-status
 *
 * Represents the current provisioning status of a project being created.
 */
class ProvisionStatus extends Data
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $slug,
        public readonly ?string $jobId,
        public readonly ?string $error,
        public readonly ?string $output,
    ) {}
}

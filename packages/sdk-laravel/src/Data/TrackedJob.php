<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Response from GET /api/jobs/{id}
 *
 * Represents an async job tracked in the orbit database.
 * Used to poll the status of background operations like project creation.
 */
class TrackedJob extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $output,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}
}

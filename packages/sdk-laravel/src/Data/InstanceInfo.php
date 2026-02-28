<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Response from GET /api/instance-info
 *
 * Represents the identity of the local orbit node.
 * Used by the desktop app to fetch the canonical display name.
 */
class InstanceInfo extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $tld,
        public readonly ?string $cliVersion,
    ) {}
}

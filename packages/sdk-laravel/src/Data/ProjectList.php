<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Response from GET /api/projects
 *
 * Contains the list of projects along with node-level config metadata.
 */
class ProjectList extends Data
{
    /**
     * @param  DataCollection<int, Project>  $projects
     * @param  array<int, string>  $availablePhpVersions
     */
    public function __construct(
        public readonly DataCollection $projects,
        public readonly string $tld,
        public readonly string $defaultPhpVersion,
        public readonly array $availablePhpVersions,
    ) {}
}

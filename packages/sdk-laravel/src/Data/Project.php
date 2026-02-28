<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Represents a single project on an orbit node.
 *
 * Returned as part of the projects list response from GET /api/projects
 * and also embedded in workspace and provision-status responses.
 */
class Project extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $displayName,
        public readonly ?string $slug,
        public readonly ?string $path,
        public readonly ?string $phpVersion,
        public readonly ?string $githubRepo,
        public readonly ?string $projectType,
        public readonly bool $hasPublicFolder,
        public readonly ?string $domain,
        public readonly ?string $url,
        public readonly ?string $status,
        public readonly ?string $errorMessage,
        public readonly ?string $jobId,
    ) {}
}

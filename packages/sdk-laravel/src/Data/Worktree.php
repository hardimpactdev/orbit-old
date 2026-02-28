<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Represents a single git worktree linked to a project.
 *
 * Returned as part of the worktrees list from GET /api/worktrees.
 * Each worktree belongs to a project and gets its own subdomain.
 */
class Worktree extends Data
{
    public function __construct(
        public readonly string $project,
        public readonly string $name,
        public readonly ?string $branch,
        public readonly string $path,
        public readonly string $domain,
        public readonly ?string $phpVersion,
        public readonly bool $secure,
    ) {}
}

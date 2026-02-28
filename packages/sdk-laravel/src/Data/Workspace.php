<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Response from GET /api/workspaces/{name}
 *
 * Represents a named workspace that groups related projects together.
 * Workspaces live in ~/workspaces/ and use symlinks to ~/projects/.
 */
class Workspace extends Data
{
    /**
     * @param  DataCollection<int, WorkspaceProject>  $projects
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly DataCollection $projects,
        public readonly int $projectCount,
        public readonly bool $hasWorkspaceFile,
        public readonly bool $hasClaudeMd,
    ) {}
}

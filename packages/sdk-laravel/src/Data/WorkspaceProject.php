<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Represents a project entry within a workspace.
 *
 * Workspaces contain symlinked project references,
 * so only the name and resolved path are available.
 */
class WorkspaceProject extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
    ) {}
}

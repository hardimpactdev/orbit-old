<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Worktrees;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/worktrees
 *
 * Returns all git worktrees across all projects on the node.
 * Each worktree has its own subdomain derived from its name and parent project.
 */
class ListWorktrees extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/worktrees';
    }
}

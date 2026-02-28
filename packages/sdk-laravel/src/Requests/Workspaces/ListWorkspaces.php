<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Workspaces;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/workspaces
 *
 * Returns all workspaces on the node.
 * Workspaces group related projects together via symlinks in ~/workspaces/.
 */
class ListWorkspaces extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/workspaces';
    }
}

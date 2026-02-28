<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Workspaces;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/workspaces/{name}
 *
 * Returns the details of a single workspace including its projects.
 */
class ShowWorkspace extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $workspaceName,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/workspaces/'.rawurlencode($this->workspaceName);
    }
}

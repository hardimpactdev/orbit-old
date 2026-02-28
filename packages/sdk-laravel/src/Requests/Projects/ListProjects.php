<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Projects;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/projects
 *
 * Returns the list of projects on the node along with TLD and PHP version metadata.
 */
class ListProjects extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/projects';
    }
}

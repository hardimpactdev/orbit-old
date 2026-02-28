<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Projects;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * POST /api/projects/{name}/rebuild
 *
 * Re-runs composer install, npm install, asset build, and migrations
 * for a project without performing a git pull.
 */
class RebuildProject extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $projectName,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/projects/'.rawurlencode($this->projectName).'/rebuild';
    }
}

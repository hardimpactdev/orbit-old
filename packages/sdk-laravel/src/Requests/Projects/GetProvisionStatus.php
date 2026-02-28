<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Projects;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/projects/{slug}/provision-status
 *
 * Polls the provisioning status of a project being created.
 * Use this to track async project creation progress.
 */
class GetProvisionStatus extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $projectSlug,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/projects/'.rawurlencode($this->projectSlug).'/provision-status';
    }
}

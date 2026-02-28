<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Config;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/config
 *
 * Returns the full orbit configuration for the node, including
 * paths, TLD, PHP versions, and project-specific settings.
 */
class GetConfig extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/config';
    }
}

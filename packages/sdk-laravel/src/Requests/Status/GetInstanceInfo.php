<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Status;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/instance-info
 *
 * Returns the identity of the local orbit node.
 * Used by the desktop app to fetch and sync the canonical display name.
 */
class GetInstanceInfo extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/instance-info';
    }
}

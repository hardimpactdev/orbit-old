<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Status;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/status
 *
 * Returns the full orbit node status including services, PHP versions,
 * project count, and CLI metadata.
 */
class GetStatus extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/status';
    }
}

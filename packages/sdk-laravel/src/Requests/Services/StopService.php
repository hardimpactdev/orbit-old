<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Services;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * POST /api/services/{service}/stop
 *
 * Stops a single Docker service by name.
 */
class StopService extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $service,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/services/'.rawurlencode($this->service).'/stop';
    }
}

<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Jobs;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/jobs/{id}
 *
 * Returns the current status of an async tracked job.
 * Use this to poll job completion for operations like project creation.
 */
class GetJob extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $jobId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/jobs/'.rawurlencode($this->jobId);
    }
}

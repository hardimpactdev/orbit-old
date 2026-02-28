<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Services;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /api/start
 *
 * Starts all services on the node. Optionally scoped to a specific project.
 */
class StartAllServices extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly ?string $project = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/start';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'project' => $this->project,
        ], fn ($v) => $v !== null);
    }
}

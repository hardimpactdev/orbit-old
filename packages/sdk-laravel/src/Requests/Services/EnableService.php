<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Services;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /api/services/{service}/enable
 *
 * Enables a Docker service, making it part of the node's service stack.
 * Optionally pass service-specific options (e.g. port mappings, image versions).
 */
class EnableService extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $service,
        private readonly array $options = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/services/'.rawurlencode($this->service).'/enable';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'options' => $this->options ?: null,
        ], fn ($v) => $v !== null);
    }
}

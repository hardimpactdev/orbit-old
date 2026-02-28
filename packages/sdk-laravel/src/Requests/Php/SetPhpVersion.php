<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Php;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /api/php/{project}
 *
 * Changes the PHP version for a specific project.
 * This updates the .php-version file and restarts the PHP-FPM pool.
 */
class SetPhpVersion extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $project,
        private readonly string $version,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/php/'.rawurlencode($this->project);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'version' => $this->version,
        ];
    }
}

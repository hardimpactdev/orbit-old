<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Projects;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * DELETE /api/projects/{name}
 *
 * Deletes a project from the node. Optionally keeps the database.
 */
class DeleteProject extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $projectName,
        private readonly bool $keepDb = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/projects/'.rawurlencode($this->projectName);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'keep_db' => $this->keepDb ?: null,
        ], fn ($v) => $v !== null);
    }
}

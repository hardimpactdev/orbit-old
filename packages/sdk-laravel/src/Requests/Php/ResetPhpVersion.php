<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Php;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * POST /api/php/{project}/reset
 *
 * Resets the PHP version for a project back to the node default.
 */
class ResetPhpVersion extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $project,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/php/'.rawurlencode($this->project).'/reset';
    }
}

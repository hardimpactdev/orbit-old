<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Php;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/php-versions
 *
 * Returns the list of PHP versions supported by this orbit node.
 * These are the versions available when creating or switching a project.
 */
class GetPhpVersions extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/php-versions';
    }
}

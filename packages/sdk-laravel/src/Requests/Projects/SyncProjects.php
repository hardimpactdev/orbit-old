<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Projects;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * POST /api/projects/sync
 *
 * Syncs projects from CLI to the database, then returns the refreshed project list.
 * Creates new records, updates existing ones, and removes orphans.
 */
class SyncProjects extends Request
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/projects/sync';
    }
}

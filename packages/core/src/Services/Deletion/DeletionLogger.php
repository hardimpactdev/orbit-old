<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Deletion;

use HardImpact\Orbit\Core\Events\ProjectDeletionStatus;
use HardImpact\Orbit\Core\Services\AbstractPipelineLogger;

/**
 * Logger for project deletion operations.
 *
 * Handles logging to Laravel logs and broadcasting status updates
 * via native Laravel events to Reverb WebSocket.
 *
 * Used by Horizon jobs for background deletion.
 */
final class DeletionLogger extends AbstractPipelineLogger
{
    protected function getLogSubdirectory(): string
    {
        return 'deletion';
    }

    protected function createBroadcastEvent(string $status, ?string $error): object
    {
        return new ProjectDeletionStatus(
            slug: $this->slug,
            status: $status,
            error: $error,
        );
    }
}

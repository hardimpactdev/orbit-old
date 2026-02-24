<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision;

use HardImpact\Orbit\Core\Events\ProjectProvisioningStatus;
use HardImpact\Orbit\Core\Services\AbstractPipelineLogger;

/**
 * Logger for project provisioning operations.
 *
 * Handles logging to Laravel logs and broadcasting status updates
 * via native Laravel events to Reverb WebSocket.
 *
 * Used by Horizon jobs for background provisioning.
 */
final class ProvisionLogger extends AbstractPipelineLogger
{
    protected function getLogSubdirectory(): string
    {
        return 'provision';
    }

    protected function createBroadcastEvent(string $status, ?string $error): object
    {
        return new ProjectProvisioningStatus(
            slug: $this->slug,
            status: $status,
            error: $error,
            projectId: $this->projectId,
        );
    }
}

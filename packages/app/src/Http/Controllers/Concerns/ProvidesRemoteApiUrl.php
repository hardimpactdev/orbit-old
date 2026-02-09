<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers\Concerns;

use HardImpact\Orbit\Core\Models\Node;

trait ProvidesRemoteApiUrl
{
    /**
     * Get the remote API URL for a node.
     * For remote nodes with a known TLD, returns https://orbit.{tld}/api
     * This allows the frontend to bypass the single-threaded NativePHP server.
     * For local nodes, returns null so frontend uses local API routes.
     */
    protected function getRemoteApiUrl(Node $node): ?string
    {
        // For remote nodes with a known TLD
        if (! $node->isLocal() && $node->tld) {
            return "https://orbit.{$node->tld}/api";
        }

        // For local nodes, use local API routes (CLI is called via ORBIT_CLI_PATH)
        return null;
    }
}

<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetWorktreesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RefreshWorktreesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\UnlinkWorktreeRequest;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;

/**
 * Service for git worktree management.
 */
class WorktreeService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command
    ) {}

    /**
     * Get all worktrees for a server (optionally filtered by project).
     */
    public function worktrees(Node $node, ?string $site = null): array
    {
        if ($node->isLocal()) {
            $command = $site
                ? "worktrees {$site} --json"
                : 'worktrees --json';

            return $this->command->executeCommand($node, $command);
        }

        return $this->connector->sendRequest($node, new GetWorktreesRequest($site));
    }

    /**
     * Unlink a worktree from a project.
     */
    public function unlinkWorktree(Node $node, string $site, string $worktreeName): array
    {
        if ($node->isLocal()) {
            $escapedSite = escapeshellarg($site);
            $escapedWorktree = escapeshellarg($worktreeName);

            return $this->command->executeCommand($node, "worktree:unlink {$escapedSite} {$escapedWorktree} --json");
        }

        return $this->connector->sendRequest($node, new UnlinkWorktreeRequest($site, $worktreeName));
    }

    /**
     * Refresh worktree detection (re-scan and auto-link new worktrees).
     */
    public function refreshWorktrees(Node $node): array
    {
        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'worktree:refresh --json');
        }

        return $this->connector->sendRequest($node, new RefreshWorktreesRequest);
    }

    /**
     * Deterministically create/setup a worktree (routing + env + deps + composer setup).
     *
     * CLI: `orbit worktree:setup <site> <worktree> --branch <branch> [--base <base>] [--force] --json`
     */
    public function setupWorktree(
        Node $node,
        string $site,
        string $worktreeName,
        string $branch,
        string $base = 'main',
        bool $force = false
    ): array {
        if (! $node->isLocal()) {
            return [
                'success' => false,
                'error' => 'worktree:setup remote execution not implemented in core yet',
            ];
        }

        $escapedSite = escapeshellarg($site);
        $escapedWorktree = escapeshellarg($worktreeName);
        $escapedBranch = escapeshellarg($branch);
        $escapedBase = escapeshellarg($base);

        $cmd = "worktree:setup {$escapedSite} {$escapedWorktree} --branch={$escapedBranch} --base={$escapedBase} --json";
        if ($force) {
            $cmd .= ' --force';
        }

        return $this->command->executeCommand($node, $cmd);
    }

}

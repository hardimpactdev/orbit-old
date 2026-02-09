<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\AddWorkspaceProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateWorkspaceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteWorkspaceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetWorkspacesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RemoveWorkspaceProjectRequest;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\WorkspaceDbService;

/**
 * Service for workspace management.
 * Uses CLI when available, falls back to database otherwise.
 */
class WorkspaceService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected WorkspaceDbService $dbService
    ) {}

    /**
     * Check if CLI is available for local environments.
     */
    protected function shouldUseCli(Node $node): bool
    {
        if (! $node->isLocal()) {
            return true; // Remote always uses CLI/API
        }

        return $this->command->isLocalCliInstalled();
    }

    /**
     * List all workspaces.
     */
    public function workspacesList(Node $node): array
    {
        if ($node->isLocal() && ! $this->shouldUseCli($node)) {
            return $this->dbService->workspacesList($node);
        }

        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'workspaces --json');
        }

        return $this->connector->sendRequest($node, new GetWorkspacesRequest);
    }

    /**
     * Create a new workspace.
     */
    public function workspaceCreate(Node $node, string $name): array
    {
        if ($node->isLocal() && ! $this->shouldUseCli($node)) {
            return $this->dbService->workspaceCreate($node, $name);
        }

        if ($node->isLocal()) {
            $escapedName = escapeshellarg($name);

            return $this->command->executeCommand($node, "workspace:create {$escapedName} --json");
        }

        return $this->connector->sendRequest($node, new CreateWorkspaceRequest($name));
    }

    /**
     * Delete a workspace.
     */
    public function workspaceDelete(Node $node, string $name): array
    {
        if ($node->isLocal() && ! $this->shouldUseCli($node)) {
            return $this->dbService->workspaceDelete($node, $name);
        }

        if ($node->isLocal()) {
            $escapedName = escapeshellarg($name);

            return $this->command->executeCommand($node, "workspace:delete {$escapedName} --force --json");
        }

        return $this->connector->sendRequest($node, new DeleteWorkspaceRequest($name));
    }

    /**
     * Add a project to a workspace.
     */
    public function workspaceAddProject(Node $node, string $workspace, string $project): array
    {
        if ($node->isLocal() && ! $this->shouldUseCli($node)) {
            return $this->dbService->workspaceAddProject($node, $workspace, $project);
        }

        if ($node->isLocal()) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->command->executeCommand($node, "workspace:add {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->connector->sendRequest($node, new AddWorkspaceProjectRequest($workspace, $project));
    }

    /**
     * Remove a project from a workspace.
     */
    public function workspaceRemoveProject(Node $node, string $workspace, string $project): array
    {
        if ($node->isLocal() && ! $this->shouldUseCli($node)) {
            return $this->dbService->workspaceRemoveProject($node, $workspace, $project);
        }

        if ($node->isLocal()) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->command->executeCommand($node, "workspace:remove {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->connector->sendRequest($node, new RemoveWorkspaceProjectRequest($workspace, $project));
    }
}

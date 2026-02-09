<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetProjectsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetStatusRequest;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\Facades\Process;

/**
 * Service for status and health-related orbit operations.
 */
class StatusService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh
    ) {}

    /**
     * Get orbit status for an environment.
     */
    public function status(Node $node): array
    {
        return $this->statusWithTimeout($node);
    }

    /**
     * Get orbit status with configurable timeout.
     */
    protected function statusWithTimeout(Node $node, int $timeout = 30): array
    {
        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'status --json');
        }

        $result = $this->connector->sendRequestWithTimeout($node, new GetStatusRequest, $timeout);

        // Cache CLI info from status response (so checkInstallation can use it)
        if ($result['success'] && isset($result['data']['cli_version'])) {
            $node->updateCliCache(
                $result['data']['cli_version'],
                $result['data']['cli_path'] ?? null
            );
        }

        return $result;
    }

    /**
     * Get all projects for an environment.
     */
    public function projects(Node $node): array
    {
        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'projects --json');
        }

        return $this->connector->sendRequest($node, new GetProjectsRequest);
    }

    /**
     * Check if orbit CLI is installed on the environment.
     */
    public function checkInstallation(Node $node): array
    {
        // For local servers, check the bundled CLI
        if ($node->isLocal()) {
            return $this->checkLocalInstallation();
        }

        // For remote servers, use cached value if fresh
        if ($node->hasCliCache()) {
            return [
                'installed' => true,
                'path' => $node->cli_path,
                'version' => $node->cli_version,
            ];
        }

        // Fetch from API and cache
        return $this->checkRemoteInstallationViaApi($node);
    }

    /**
     * Check local CLI installation status.
     */
    protected function checkLocalInstallation(): array
    {
        if (! $this->command->isLocalCliInstalled()) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $pharPath = $this->command->getLocalCliPath();
        $result = Process::timeout(10)->run("php {$pharPath} --version");

        return [
            'installed' => true,
            'path' => $pharPath,
            'version' => trim($result->output()),
        ];
    }

    /**
     * Check remote installation via HTTP API (fast, uses status endpoint).
     * Falls back to SSH if API fails.
     */
    protected function checkRemoteInstallationViaApi(Node $node): array
    {
        // Try to get CLI info from status endpoint (includes cli_version and cli_path)
        // Use a short timeout (5s) for this check to avoid blocking app startup
        $status = $this->statusWithTimeout($node, timeout: 5);

        if ($status['success'] && isset($status['data']['cli_version'])) {
            $version = $status['data']['cli_version'];
            $path = $status['data']['cli_path'] ?? null;

            // Cache the result
            $node->updateCliCache($version, $path);

            return [
                'installed' => true,
                'path' => $path,
                'version' => $version,
            ];
        }

        // Fallback to SSH-based check if API doesn't have CLI info
        return $this->checkRemoteInstallationViaSsh($node);
    }

    /**
     * Check remote installation via SSH (slow, used as fallback).
     */
    protected function checkRemoteInstallationViaSsh(Node $node): array
    {
        $path = $this->command->findBinary($node);

        if (! $path) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $versionResult = $this->ssh->execute($node, "{$path} --version");
        $version = trim((string) $versionResult['output']);

        // Cache the result
        $node->updateCliCache($version, $path);

        return [
            'installed' => true,
            'path' => $path,
            'version' => $version,
        ];
    }
}

<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ConfigureServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DisableServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\EnableServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetServiceInfoRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetServiceLogsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ListAvailableServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ListServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RestartServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RestartServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StartServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StartServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StopServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StopServicesRequest;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\HorizonService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\Facades\Process;

/**
 * Service for controlling orbit Docker services.
 */
class ServiceControlService
{
    private const ALLOWED_DOCKER_SERVICES = ['dns', 'postgres', 'redis', 'mailpit', 'reverb'];

    private const ALLOWED_HOST_SERVICES = ['caddy', 'horizon', 'horizon-dev', 'php-8.1', 'php-8.2', 'php-8.3', 'php-8.4', 'php-8.5'];

    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh,
        protected HorizonService $horizon
    ) {}

    private function validateServiceName(string $service, array $allowlist): void
    {
        if (! in_array($service, $allowlist, true)) {
            throw new \InvalidArgumentException("Invalid service name '{$service}'. Allowed: " . implode(', ', $allowlist));
        }
    }

    /**
     * List all services and their status.
     */
    public function list(Node $node): array
    {
        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'service:list --json');
        }

        return $this->connector->sendRequest($node, new ListServicesRequest);
    }

    /**
     * List available services that can be enabled.
     */
    public function available(Node $node): array
    {
        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'service:list --available --json');
        }

        return $this->connector->sendRequest($node, new ListAvailableServicesRequest);
    }

    /**
     * Enable a service.
     */
    public function enable(Node $node, string $service, array $options = []): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);

        if ($node->isLocal()) {
            return $this->command->executeCommand($node, "service:enable {$service} --json");
        }

        return $this->connector->sendRequest($node, new EnableServiceRequest($service, $options));
    }

    /**
     * Disable a service.
     */
    public function disable(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);

        if ($node->isLocal()) {
            return $this->command->executeCommand($node, "service:disable {$service} --json");
        }

        return $this->connector->sendRequest($node, new DisableServiceRequest($service));
    }

    /**
     * Update service configuration.
     */
    public function configure(Node $node, string $service, array $config): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);

        if ($node->isLocal()) {
            $configJson = json_encode($config);
            $escapedConfig = escapeshellarg($configJson);

            return $this->command->executeCommand($node, "service:config {$service} --config={$escapedConfig} --json");
        }

        return $this->connector->sendRequest($node, new ConfigureServiceRequest($service, $config));
    }

    /**
     * Get detailed info for a service.
     */
    public function info(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);

        if ($node->isLocal()) {
            return $this->command->executeCommand($node, "service:info {$service} --json");
        }

        return $this->connector->sendRequest($node, new GetServiceInfoRequest($service));
    }

    /**
     * Start orbit services.
     */
    public function start(Node $node, ?string $site = null): array
    {
        if ($node->isLocal()) {
            $command = $site ? "start {$site} --json" : 'start --json';

            return $this->command->executeCommand($node, $command);
        }

        // Note: Project-specific start not supported via API yet
        return $this->connector->sendRequest($node, new StartServicesRequest);
    }

    /**
     * Stop orbit services.
     */
    public function stop(Node $node, ?string $site = null): array
    {
        if ($node->isLocal()) {
            $command = $site ? "stop {$site} --json" : 'stop --json';

            return $this->command->executeCommand($node, $command);
        }

        return $this->connector->sendRequest($node, new StopServicesRequest);
    }

    /**
     * Restart orbit services.
     */
    public function restart(Node $node, ?string $site = null): array
    {
        if ($node->isLocal()) {
            $command = $site ? "restart {$site} --json" : 'restart --json';

            return $this->command->executeCommand($node, $command);
        }

        return $this->connector->sendRequest($node, new RestartServicesRequest);
    }

    /**
     * Start a single service via Docker.
     */
    public function startService(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);
        $container = $this->getContainerName($service);

        if ($node->isLocal()) {
            return $this->dockerServiceAction($node, $container, 'start');
        }

        return $this->connector->sendRequest($node, new StartServiceRequest($container));
    }

    /**
     * Stop a single service via Docker.
     */
    public function stopService(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);
        $container = $this->getContainerName($service);

        if ($node->isLocal()) {
            return $this->dockerServiceAction($node, $container, 'stop');
        }

        return $this->connector->sendRequest($node, new StopServiceRequest($container));
    }

    /**
     * Restart a single service via Docker.
     */
    public function restartService(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);
        $container = $this->getContainerName($service);

        if ($node->isLocal()) {
            return $this->dockerServiceAction($node, $container, 'restart');
        }

        return $this->connector->sendRequest($node, new RestartServiceRequest($container));
    }

    /**
     * Start a host service (Caddy, PHP-FPM, Horizon).
     */
    public function startHostService(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_HOST_SERVICES);

        if ($node->isLocal()) {
            return $this->hostServiceAction($node, $service, 'start');
        }

        return $this->command->executeCommand($node, "host:start {$service} --json");
    }

    /**
     * Stop a host service (Caddy, PHP-FPM, Horizon).
     */
    public function stopHostService(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_HOST_SERVICES);

        if ($node->isLocal()) {
            return $this->hostServiceAction($node, $service, 'stop');
        }

        return $this->command->executeCommand($node, "host:stop {$service} --json");
    }

    /**
     * Restart a host service (Caddy, PHP-FPM, Horizon).
     */
    public function restartHostService(Node $node, string $service): array
    {
        $this->validateServiceName($service, self::ALLOWED_HOST_SERVICES);

        if ($node->isLocal()) {
            return $this->hostServiceAction($node, $service, 'restart');
        }

        return $this->command->executeCommand($node, "host:restart {$service} --json");
    }

    /**
     * Get logs for a single service.
     *
     * @param  string|null  $since  ISO 8601 timestamp to fetch logs since (for "clear" functionality)
     */
    public function serviceLogs(Node $node, string $service, int $lines = 200, ?string $since = null): array
    {
        $this->validateServiceName($service, self::ALLOWED_DOCKER_SERVICES);
        $container = $this->getContainerName($service);

        if ($node->isLocal()) {
            $cmd = 'docker logs --timestamps';

            if ($since) {
                // Use --since for filtering (clears old logs from view)
                $cmd .= ' --since '.escapeshellarg($since);
            } else {
                // Default: show last N lines
                $cmd .= " --tail {$lines}";
            }

            $cmd .= " {$container} 2>&1";

            $result = Process::timeout(30)->run($cmd);

            return [
                'success' => true,
                'logs' => $result->output() ?: 'No logs available',
            ];
        }

        return $this->connector->sendRequest($node, new GetServiceLogsRequest($container, $lines));
    }

    /**
     * Get logs for a host service (Caddy, PHP-FPM, Horizon).
     *
     * @param  string|null  $since  ISO 8601 timestamp to fetch logs since (for "clear" functionality)
     */
    public function hostServiceLogs(Node $node, string $service, int $lines = 200, ?string $since = null): array
    {
        $this->validateServiceName($service, self::ALLOWED_HOST_SERVICES);

        if ($node->isLocal()) {
            return $this->getLocalHostServiceLogs($service, $lines, $since);
        }

        // For remote environments, delegate to CLI
        return $this->command->executeCommand($node, "host:logs {$service} --lines={$lines} --json");
    }

    /**
     * Get logs for a local host service.
     *
     * @param  string|null  $since  ISO 8601 timestamp to fetch logs since
     */
    protected function getLocalHostServiceLogs(string $service, int $lines, ?string $since = null): array
    {
        // Use HorizonService for horizon logs
        if ($service === 'horizon' || $service === 'horizon-dev') {
            return [
                'success' => true,
                'logs' => $this->horizon->getLogs($lines) ?: 'No logs available',
            ];
        }

        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            // macOS: use Homebrew log locations or journalctl equivalent
            if ($service === 'caddy') {
                // Caddy logs via brew services
                $logPath = '/opt/homebrew/var/log/caddy.log';
                if (! file_exists($logPath)) {
                    $logPath = '/usr/local/var/log/caddy.log';
                }
            } elseif (str_starts_with($service, 'php')) {
                // PHP-FPM logs - Homebrew uses a single shared log file
                $logPath = '/opt/homebrew/var/log/php-fpm.log';
                if (! file_exists($logPath)) {
                    $logPath = '/usr/local/var/log/php-fpm.log';
                }
            } else {
                return [
                    'success' => false,
                    'error' => "Unknown host service: {$service}",
                ];
            }

            if (file_exists($logPath)) {
                $result = Process::timeout(10)->run("tail -n {$lines} ".escapeshellarg($logPath));

                return [
                    'success' => true,
                    'logs' => $result->output() ?: 'No logs available',
                ];
            }

            return [
                'success' => true,
                'logs' => "Log file not found: {$logPath}",
            ];
        } else {
            // Linux: use journalctl with timestamps
            $unit = $service;
            if (str_starts_with($service, 'php')) {
                $version = str_replace('php-', '', $service);
                $unit = "php{$version}-fpm";
            }

            $cmd = "journalctl -u {$unit} --no-pager -o short-iso";

            if ($since) {
                // Use --since for filtering (clears old logs from view)
                $cmd .= ' --since '.escapeshellarg($since);
            } else {
                // Default: show last N lines
                $cmd .= " -n {$lines}";
            }

            $cmd .= ' 2>&1';

            $result = Process::timeout(10)->run($cmd);

            return [
                'success' => true,
                'logs' => $result->output() ?: 'No logs available',
            ];
        }
    }

    /**
     * Rebuild the DNS container with the correct TLD and HOST_IP.
     * This is needed when TLD changes on a remote server.
     * Also restarts orbit to regenerate Caddy config with new domains.
     */
    public function rebuildDns(Node $node, string $tld): array
    {
        // For local servers, just restart orbit (handles DNS automatically)
        if ($node->isLocal()) {
            return $this->restartWithoutJson($node);
        }

        // For remote servers, rebuild the DNS container with correct TLD and HOST_IP
        $hostIp = $node->host;
        $escapedTld = escapeshellarg($tld);
        $escapedHostIp = escapeshellarg($hostIp);

        // Stop and remove existing DNS container
        $this->ssh->execute($node, 'sg docker -c "docker stop orbit-dns 2>/dev/null || true"');
        $this->ssh->execute($node, 'sg docker -c "docker rm orbit-dns 2>/dev/null || true"');

        // Rebuild DNS image with correct TLD and HOST_IP
        $buildCommand = "sg docker -c 'cd ~/.config/orbit/dns && TLD={$escapedTld} HOST_IP={$escapedHostIp} docker compose build --no-cache'";
        $buildResult = $this->ssh->execute($node, $buildCommand, 120); // 2 min timeout for build

        if (! $buildResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to rebuild DNS container: '.($buildResult['error'] ?? 'Unknown error'),
            ];
        }

        // Restart orbit to regenerate all configs (Caddy, etc.) with new TLD
        // This also starts the DNS container with the rebuilt image
        // Use restartWithoutJson to avoid JSON parsing errors from orbit output
        $restartResult = $this->restartWithoutJson($node);

        if (! $restartResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to restart orbit after DNS rebuild: '.($restartResult['error'] ?? 'Unknown error'),
            ];
        }

        return [
            'success' => true,
            'message' => "DNS rebuilt and orbit restarted with TLD={$tld}",
        ];
    }

    /**
     * Restart orbit without expecting JSON output.
     * Used by rebuildDns where we just need success/failure, not parsed data.
     */
    protected function restartWithoutJson(Node $node): array
    {
        return $this->command->executeRawCommand($node, 'restart');
    }

    /**
     * Execute a host service action.
     */
    protected function hostServiceAction(Node $node, string $service, string $action): array
    {
        // Use HorizonService for horizon actions
        if ($service === 'horizon' || $service === 'horizon-dev') {
            $success = match ($action) {
                'start' => $this->horizon->start(),
                'stop' => $this->horizon->stop(),
                'restart' => $this->horizon->restart(),
                default => false,
            };

            if (! $success) {
                return [
                    'success' => false,
                    'error' => "Failed to {$action} horizon",
                ];
            }

            return ['success' => true];
        }

        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            // For PHP-FPM and Caddy on Mac (Homebrew)
            $brewService = $service;
            if (str_starts_with($service, 'php')) {
                // php-8.3 -> php@8.3
                $brewService = str_replace('php-', 'php@', $service);
            }
            $result = Process::run("brew services {$action} {$brewService}");
        } else {
            // Linux: systemctl
            $unit = $service;
            if (str_starts_with($service, 'php')) {
                // php-8.3 -> php8.3-fpm
                $version = str_replace('php-', '', $service);
                $unit = "php{$version}-fpm";
            }
            $result = Process::run("sudo systemctl {$action} {$unit}");
        }

        if (! $result->successful()) {
            return [
                'success' => false,
                'error' => $result->errorOutput() ?: "Failed to {$action} {$service}",
            ];
        }

        return ['success' => true];
    }

    /**
     * Execute a Docker action on a container.
     */
    protected function dockerServiceAction(Node $node, string $container, string $action): array
    {
        if ($node->isLocal()) {
            $result = Process::timeout(60)
                ->run("docker {$action} {$container}");

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'error' => $result->errorOutput() ?: "Failed to {$action} {$container}",
                ];
            }

            return ['success' => true];
        }

        $result = $this->ssh->execute($node, "sg docker -c 'docker {$action} {$container}'");

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? "Failed to {$action} {$container}",
            ];
        }

        return ['success' => true];
    }

    /**
     * Convert service key to Docker container name.
     */
    protected function getContainerName(string $service): string
    {
        // Map Docker service keys to container names
        $containerMap = [
            'dns' => 'orbit-dns',
            'postgres' => 'orbit-postgres',
            'redis' => 'orbit-redis',
            'mailpit' => 'orbit-mailpit',
            'reverb' => 'orbit-reverb',
        ];

        return $containerMap[$service] ?? 'orbit-'.$service;
    }
}

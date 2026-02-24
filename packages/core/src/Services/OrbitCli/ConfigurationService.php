<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetConfigRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetPhpVersionsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ResetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\SetPhpRequest;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\SshService;
use HardImpact\Orbit\Core\Support\PhpVersion;

/**
 * Service for orbit configuration management.
 */
class ConfigurationService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh,
        protected StatusService $status
    ) {}

    /**
     * Get orbit configuration for an environment.
     */
    public function getConfig(Node $node): array
    {
        if ($node->isLocal()) {
            return $this->getLocalConfig();
        }

        return $this->getRemoteConfig($node);
    }

    /**
     * Save orbit configuration for an environment.
     */
    public function saveConfig(Node $node, array $config): array
    {
        if ($node->isLocal()) {
            return $this->saveLocalConfig($config);
        }

        return $this->saveRemoteConfig($node, $config);
    }

    /**
     * Get or set PHP version for a project.
     */
    public function php(Node $node, string $site, ?string $version = null): array
    {
        if ($node->isLocal()) {
            $escapedSite = escapeshellarg($site);
            $command = $version
                ? "php {$escapedSite} ".escapeshellarg($version).' --json'
                : "php {$escapedSite} --json";

            return $this->command->executeCommand($node, $command);
        }

        if ($version) {
            return $this->connector->sendRequest($node, new SetPhpRequest($site, $version));
        }

        return $this->connector->sendRequest($node, new GetPhpRequest($site));
    }

    /**
     * Reset PHP version for a project to default.
     */
    public function phpReset(Node $node, string $site): array
    {
        if ($node->isLocal()) {
            return $this->command->executeCommand($node, 'php '.escapeshellarg($site).' --reset --json');
        }

        return $this->connector->sendRequest($node, new ResetPhpRequest($site));
    }

    /**
     * Get Reverb WebSocket configuration for real-time updates.
     * Uses status endpoint to check if Reverb service is running.
     */
    public function getReverbConfig(Node $node): array
    {
        $status = $this->status->status($node);

        if (! $status['success']) {
            return [
                'success' => false,
                'error' => $status['error'] ?? 'Failed to get status',
            ];
        }

        $statusData = $status['data'] ?? [];
        $services = $statusData['services'] ?? [];

        // Check if Reverb is running
        $reverbService = $services['reverb'] ?? null;
        $reverbRunning = ($reverbService['status'] ?? '') === 'running';

        if (! $reverbRunning) {
            return [
                'success' => true,
                'enabled' => false,
            ];
        }

        // Get TLD from environment cache or status
        $tld = $node->tld ?? $statusData['tld'] ?? 'test';

        // Caddy terminates TLS for reverb.{tld} and proxies to the Reverb container
        return [
            'success' => true,
            'enabled' => true,
            'host' => "reverb.{$tld}",
            'port' => 443,
            'scheme' => 'https',
            'app_key' => 'orbit-key',
        ];
    }

    /**
     * Get formatted reverb config for Inertia page props.
     */
    public function getFormattedReverbConfig(Node $node): array
    {
        $reverb = $this->getReverbConfig($node);

        if (! $reverb['success']) {
            return ['enabled' => false];
        }

        return [
            'enabled' => $reverb['enabled'] ?? false,
            'host' => $reverb['host'] ?? null,
            'port' => $reverb['port'] ?? null,
            'scheme' => $reverb['scheme'] ?? null,
            'app_key' => $reverb['app_key'] ?? null,
        ];
    }

    /**
     * Get default configuration values.
     */
    public function getDefaultConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '/home/user');

        return [
            'paths' => [$home.'/projects'],
            'tld' => 'test',
            'default_php_version' => '8.4',
        ];
    }

    /**
     * Get local configuration.
     */
    protected function getLocalConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/orbit/config.json';

        if (! file_exists($configPath)) {
            $data = $this->getDefaultConfig();
            $data['available_php_versions'] = $this->getLocalAvailablePhpVersions();

            return [
                'success' => true,
                'data' => $data,
                'exists' => false,
            ];
        }

        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Failed to parse config: '.json_last_error_msg(),
            ];
        }

        $data = array_merge($this->getDefaultConfig(), $config);
        $data['available_php_versions'] = $this->getLocalAvailablePhpVersions();

        return [
            'success' => true,
            'data' => $data,
            'exists' => true,
        ];
    }

    /**
     * Get available PHP versions for local environment.
     */
    protected function getLocalAvailablePhpVersions(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        $sockets = glob($home.'/.config/orbit/php/php*.sock') ?: [];
        $versions = [];

        foreach ($sockets as $socket) {
            $name = basename($socket, '.sock');
            if (preg_match('/^php(\d{2})$/', $name, $matches)) {
                $digits = $matches[1];
                $versions[] = substr($digits, 0, 1).'.'.substr($digits, 1);
            }
        }

        usort($versions, version_compare(...));

        return $versions === [] ? PhpVersion::SUPPORTED : $versions;
    }

    /**
     * Get remote configuration via HTTP API.
     */
    protected function getRemoteConfig(Node $node): array
    {
        // Use HTTP API to get config
        $result = $this->connector->sendRequest($node, new GetConfigRequest);

        if (! $result['success']) {
            // Fallback to defaults if API fails
            $data = $this->getDefaultConfig();
            $data['available_php_versions'] = $this->getAvailablePhpVersions($node);

            return [
                'success' => true,
                'data' => $data,
                'exists' => false,
            ];
        }

        // API returns flat response, merge with defaults
        $data = array_merge($this->getDefaultConfig(), $result);
        unset($data['success']); // Remove success flag from data

        $data['available_php_versions'] = $this->getAvailablePhpVersions($node);

        return [
            'success' => true,
            'data' => $data,
            'exists' => true,
        ];
    }

    /**
     * Get available PHP versions by scanning running containers.
     */
    protected function getAvailablePhpVersions(Node $node): array
    {
        // For remote environments, use HTTP API
        if (! $node->isLocal()) {
            $result = $this->connector->sendRequest($node, new GetPhpVersionsRequest);
            if ($result['success'] && isset($result['versions'])) {
                return $result['versions'];
            }

            // Fallback to default versions
            return PhpVersion::SUPPORTED;
        }

        return $this->getLocalAvailablePhpVersions();
    }

    /**
     * Save local configuration.
     */
    protected function saveLocalConfig(array $config): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configDir = $home.'/.config/orbit';
        $configPath = $configDir.'/config.json';

        // Ensure directory exists
        if (! is_dir($configDir) && ! mkdir($configDir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'Failed to create config directory',
            ];
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($configPath, $json) === false) {
            return [
                'success' => false,
                'error' => 'Failed to write config file',
            ];
        }

        return [
            'success' => true,
            'data' => $config,
        ];
    }

    /**
     * Save config to remote environment via SSH.
     *
     * Note: Config write intentionally uses SSH rather than HTTP API.
     * The orbit-cli API does not expose a config write endpoint for security
     * reasons - allowing arbitrary config changes via HTTP could be exploited.
     * Config reads use the API (fast, no SSH overhead), but writes require SSH
     * authentication which provides an additional security layer.
     */
    protected function saveRemoteConfig(Node $node, array $config): array
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $escapedJson = escapeshellarg($json);

        // Ensure directory exists and write file
        $command = "mkdir -p ~/.config/orbit && echo {$escapedJson} > ~/.config/orbit/config.json";
        $result = $this->ssh->execute($node, $command);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to save config',
            ];
        }

        return [
            'success' => true,
            'data' => $config,
        ];
    }

    /**
     * Get DNS mappings for an environment.
     */
    public function getDnsMappings(Node $node): array
    {
        $configResult = $this->getConfig($node);

        if (! $configResult['success']) {
            return $configResult;
        }

        $config = $configResult['data'];
        $mappings = $config['dns_mappings'] ?? [
            ['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1'],
            ['type' => 'server', 'value' => '8.8.8.8'],
            ['type' => 'server', 'value' => '8.8.4.4'],
        ];

        return [
            'success' => true,
            'mappings' => $mappings,
        ];
    }

    /**
     * Set DNS mappings for an environment.
     */
    public function setDnsMappings(Node $node, array $mappings): array
    {
        $configResult = $this->getConfig($node);

        if (! $configResult['success']) {
            return $configResult;
        }

        $config = $configResult['data'];
        $config['dns_mappings'] = $mappings;

        return $this->saveConfig($node, $config);
    }
}

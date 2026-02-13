<?php

declare(strict_types=1);

namespace App\Data\Install;

use HardImpact\Orbit\Core\Enums\NodeType;

final class InstallContext
{
    /**
     * @param  array<int, string>  $phpVersions
     * @param  array<int, string>  $services
     * @param  array<int, string>  $nodePackageManagers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $tld = 'test',
        public readonly array $phpVersions = ['8.5'],
        public readonly bool $skipDocker = false,
        public readonly bool $skipTrust = false,
        public readonly bool $nonInteractive = false,
        public readonly string $configDir = '',
        public readonly string $homeDir = '',
        public readonly string $template = 'php-dev',
        public readonly array $services = [],
        public readonly array $nodePackageManagers = [],
        public readonly NodeType $nodeType = NodeType::Local,
        public readonly bool $skipOrbitCli = false,
        public readonly ?int $gatewayId = null,
        public readonly ?string $nodeName = null,
        public readonly ?string $hostIp = null,
        public array $metadata = [],
    ) {}

    public function needsNode(): bool
    {
        return array_intersect(['npm', 'yarn', 'pnpm'], $this->nodePackageManagers) !== [];
    }

    public function needsDocker(): bool
    {
        return $this->services !== [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options, string $template = 'php-dev'): self
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $phpVersionsOption = $options['php-versions'] ?? '8.5';
        $phpVersions = is_array($phpVersionsOption)
            ? $phpVersionsOption
            : array_map(trim(...), explode(',', (string) $phpVersionsOption));

        $servicesOption = $options['services'] ?? '';
        $services = is_string($servicesOption) && $servicesOption !== ''
            ? array_map(trim(...), explode(',', $servicesOption))
            : [];

        $nodePackagesOption = $options['node-packages'] ?? '';
        $nodePackageManagers = is_string($nodePackagesOption) && $nodePackagesOption !== ''
            ? array_map(trim(...), explode(',', $nodePackagesOption))
            : [];

        $nodeType = isset($options['node-type'])
            ? NodeType::from((string) $options['node-type'])
            : NodeType::Local;

        return new self(
            tld: (string) ($options['tld'] ?? 'test'),
            phpVersions: array_map(fn ($v) => self::normalizePhpVersion((string) $v), $phpVersions),
            skipDocker: (bool) ($options['skip-docker'] ?? false),
            skipTrust: (bool) ($options['skip-trust'] ?? false),
            nonInteractive: (bool) ($options['yes'] ?? false),
            configDir: "{$home}/.config/orbit",
            homeDir: $home,
            template: $template,
            services: $services,
            nodePackageManagers: $nodePackageManagers,
            nodeType: $nodeType,
            skipOrbitCli: (bool) ($options['skip-cli'] ?? false),
            gatewayId: isset($options['gateway-id']) ? (int) $options['gateway-id'] : null,
            nodeName: isset($options['node-name']) ? (string) $options['node-name'] : null,
            hostIp: isset($options['host-ip']) ? (string) $options['host-ip'] : null,
        );
    }

    /**
     * Normalize PHP version to X.Y format (e.g., "84" -> "8.4", "8.4" -> "8.4")
     */
    private static function normalizePhpVersion(string $version): string
    {
        $version = str_replace(['php@', 'php'], '', $version);

        if (! str_contains($version, '.')) {
            $version = substr($version, 0, 1).'.'.substr($version, 1);
        }

        return $version;
    }
}

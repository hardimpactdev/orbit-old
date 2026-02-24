<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\RemoteDeploy;

use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;

final readonly class RemoteDeployContext
{
    public string $basePath;

    public string $releasePath;

    public string $currentLink;

    public function __construct(
        public Node $node,
        public string $slug,
        public string $repo,
        public string $timestamp,
        public Deployment $deployment,
        public ?GatewayProject $project = null,
        public ?string $phpVersion = null,
        public int $keepReleases = 5,
    ) {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $this->slug)) {
            throw new \InvalidArgumentException("Invalid slug '{$this->slug}': must contain only lowercase alphanumeric characters and hyphens");
        }

        $this->basePath = "~/projects/{$this->slug}";
        $this->releasePath = "{$this->basePath}/releases/{$this->timestamp}";
        $this->currentLink = "{$this->basePath}/current";
    }

    public function domain(): ?string
    {
        if ($this->project) {
            return $this->project->domainForNode($this->node);
        }

        return $this->node->tld ? "{$this->slug}.{$this->node->tld}" : null;
    }

    public function appUrl(): string
    {
        $domain = $this->domain();

        return $domain ? "https://{$domain}" : "https://{$this->slug}";
    }

    public function phpVersionClean(): string
    {
        if (! $this->phpVersion) {
            throw new \RuntimeException('PHP version not set — auto-detection may have failed. Specify php_version explicitly.');
        }

        return str_replace('.', '', $this->phpVersion);
    }

    public function socketPath(): string
    {
        return "~/.config/orbit/php/php{$this->phpVersionClean()}.sock";
    }
}

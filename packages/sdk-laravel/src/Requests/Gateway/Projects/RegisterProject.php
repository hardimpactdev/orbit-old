<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Projects;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class RegisterProject extends McpRequest
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $slug = null,
        private readonly ?string $githubRepo = null,
        private readonly ?string $productionDomain = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_register_project';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = ['name' => $this->name];

        if ($this->slug !== null) {
            $args['slug'] = $this->slug;
        }
        if ($this->githubRepo !== null) {
            $args['github_repo'] = $this->githubRepo;
        }
        if ($this->productionDomain !== null) {
            $args['production_domain'] = $this->productionDomain;
        }

        return $args;
    }
}

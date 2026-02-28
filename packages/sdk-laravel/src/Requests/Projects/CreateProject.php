<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Projects;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /api/projects
 *
 * Creates a new project on the node. Supports blank projects, GitHub template cloning,
 * and repository imports. Returns the project slug and initial provisioning status.
 */
class CreateProject extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $name,
        private readonly ?string $template = null,
        private readonly ?string $org = null,
        private readonly bool $isTemplate = false,
        private readonly bool $fork = false,
        private readonly string $visibility = 'private',
        private readonly ?string $phpVersion = null,
        private readonly ?string $dbDriver = null,
        private readonly ?string $sessionDriver = null,
        private readonly ?string $cacheDriver = null,
        private readonly ?string $queueDriver = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/projects';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'visibility' => $this->visibility,
        ];

        if ($this->template !== null) {
            $body['template'] = $this->template;
        }

        if ($this->org !== null) {
            $body['org'] = $this->org;
        }

        if ($this->isTemplate) {
            $body['is_template'] = true;
        }

        if ($this->fork) {
            $body['fork'] = true;
        }

        if ($this->phpVersion !== null) {
            $body['php_version'] = $this->phpVersion;
        }

        if ($this->dbDriver !== null) {
            $body['db_driver'] = $this->dbDriver;
        }

        if ($this->sessionDriver !== null) {
            $body['session_driver'] = $this->sessionDriver;
        }

        if ($this->cacheDriver !== null) {
            $body['cache_driver'] = $this->cacheDriver;
        }

        if ($this->queueDriver !== null) {
            $body['queue_driver'] = $this->queueDriver;
        }

        return $body;
    }
}

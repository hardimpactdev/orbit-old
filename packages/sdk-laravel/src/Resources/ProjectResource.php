<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Data\ProjectList;
use HardImpact\Orbit\Data\ProvisionStatus;
use HardImpact\Orbit\Requests\Projects\CreateProject;
use HardImpact\Orbit\Requests\Projects\DeleteProject;
use HardImpact\Orbit\Requests\Projects\GetProvisionStatus;
use HardImpact\Orbit\Requests\Projects\ListProjects;
use HardImpact\Orbit\Requests\Projects\RebuildProject;
use HardImpact\Orbit\Requests\Projects\SyncProjects;
use Saloon\Http\BaseResource;

class ProjectResource extends BaseResource
{
    public function list(): ProjectList
    {
        return ProjectList::from($this->connector->send(new ListProjects)->json());
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        string $name,
        ?string $template = null,
        ?string $org = null,
        ?string $phpVersion = null,
        ?string $dbDriver = null,
        ?string $cacheDriver = null,
        ?string $sessionDriver = null,
    ): array {
        return $this->connector->send(new CreateProject(
            name: $name,
            template: $template,
            org: $org,
            phpVersion: $phpVersion,
            dbDriver: $dbDriver,
            cacheDriver: $cacheDriver,
            sessionDriver: $sessionDriver,
        ))->json();
    }

    public function delete(string $projectName, bool $keepDb = false): bool
    {
        return $this->connector->send(new DeleteProject($projectName, $keepDb))->successful();
    }

    public function rebuild(string $projectName): bool
    {
        return $this->connector->send(new RebuildProject($projectName))->successful();
    }

    public function provisionStatus(string $projectSlug): ProvisionStatus
    {
        return ProvisionStatus::from(
            $this->connector->send(new GetProvisionStatus($projectSlug))->json()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(): array
    {
        return $this->connector->send(new SyncProjects)->json();
    }
}

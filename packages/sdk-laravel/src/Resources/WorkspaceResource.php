<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Data\Workspace;
use HardImpact\Orbit\Requests\Workspaces\ListWorkspaces;
use HardImpact\Orbit\Requests\Workspaces\ShowWorkspace;
use Saloon\Http\BaseResource;

class WorkspaceResource extends BaseResource
{
    /**
     * @return Workspace[]
     */
    public function list(): array
    {
        $data = $this->connector->send(new ListWorkspaces)->json();

        return array_map(Workspace::from(...), $data['workspaces'] ?? []);
    }

    public function show(string $name): Workspace
    {
        return Workspace::from(
            $this->connector->send(new ShowWorkspace($name))->json()
        );
    }
}

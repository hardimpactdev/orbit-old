<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\GatewayProject;
use HardImpact\Orbit\Requests\Gateway\Projects\ListGatewayProjects;
use HardImpact\Orbit\Requests\Gateway\Projects\RegisterProject;

class GatewayProjectResource extends GatewayResource
{
    /**
     * @return GatewayProject[]
     */
    public function list(?string $slug = null): array
    {
        $response = $this->connector->send(new ListGatewayProjects($slug));
        $data = $this->unwrap($response);

        return array_map(
            fn (array $project) => GatewayProject::from([
                'id' => $project['id'],
                'slug' => $project['slug'],
                'name' => $project['name'],
                'githubRepo' => $project['github_repo'] ?? null,
                'productionDomain' => $project['production_domain'] ?? null,
                'cloudflareZoneId' => $project['cloudflare_zone_id'] ?? null,
                'cloudflareZoneName' => $project['cloudflare_zone_name'] ?? null,
                'activeDeployments' => $project['active_deployments'] ?? null,
                'createdAt' => $project['created_at'] ?? null,
            ]),
            $data['projects'] ?? [],
        );
    }

    public function register(
        string $name,
        ?string $slug = null,
        ?string $githubRepo = null,
        ?string $productionDomain = null,
    ): GatewayProject {
        $response = $this->connector->send(new RegisterProject($name, $slug, $githubRepo, $productionDomain));
        $data = $this->unwrap($response);
        $project = $data['project'];

        return GatewayProject::from([
            'id' => $project['id'],
            'slug' => $project['slug'],
            'name' => $project['name'],
            'githubRepo' => $project['github_repo'] ?? null,
            'productionDomain' => $project['production_domain'] ?? null,
            'cloudflareZoneId' => $project['cloudflare_zone_id'] ?? null,
            'cloudflareZoneName' => $project['cloudflare_zone_name'] ?? null,
        ]);
    }
}

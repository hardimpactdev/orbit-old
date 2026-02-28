<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\Deployment;
use HardImpact\Orbit\Requests\Gateway\Deployments\Deploy;
use HardImpact\Orbit\Requests\Gateway\Deployments\ListDeployments;
use HardImpact\Orbit\Requests\Gateway\Deployments\Undeploy;

class DeploymentResource extends GatewayResource
{
    /**
     * @return Deployment[]
     */
    public function list(
        ?string $project = null,
        ?int $nodeId = null,
        ?string $environment = null,
        ?string $status = null,
    ): array {
        $response = $this->connector->send(new ListDeployments($project, $nodeId, $environment, $status));
        $data = $this->unwrap($response);

        return array_map(
            fn (array $d) => Deployment::from([
                'id' => $d['id'],
                'projectSlug' => $d['project_slug'],
                'projectName' => $d['project_name'] ?? null,
                'githubRepo' => $d['github_repo'] ?? null,
                'domain' => $d['domain'] ?? null,
                'url' => $d['url'] ?? null,
                'phpVersion' => $d['php_version'] ?? null,
                'status' => $d['status'],
                'errorMessage' => $d['error_message'] ?? null,
                'node' => $d['node'] ?? [],
                'createdAt' => $d['created_at'] ?? null,
                'cloudflareRecordId' => $d['cloudflare_record_id'] ?? null,
            ]),
            $data['deployments'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function deploy(
        int $nodeId,
        ?string $projectSlug = null,
        ?string $name = null,
        ?string $clone = null,
        ?string $template = null,
        ?string $phpVersion = null,
        ?string $domain = null,
    ): array {
        $response = $this->connector->send(new Deploy(
            $nodeId,
            $projectSlug,
            $name,
            $clone,
            $template,
            $phpVersion,
            $domain,
        ));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function undeploy(int $deploymentId): array
    {
        $response = $this->connector->send(new Undeploy($deploymentId));

        return $this->unwrap($response);
    }
}
